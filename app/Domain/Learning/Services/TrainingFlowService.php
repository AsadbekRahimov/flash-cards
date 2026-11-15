<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Content\Services\DeckTitles;
use App\Domain\Content\Services\WordAudioService;
use App\Domain\Content\Services\WordExamples;
use App\Domain\Content\Services\WordHookResolver;
use App\Domain\Content\Services\WordTranslationResolver;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learning\Contracts\NewCardBudgetResolver;
use App\Domain\Learning\DTO\AcceptedReview;
use App\Domain\Learning\DTO\LearnerExerciseSettings;
use App\Domain\Learning\DTO\RevealedCard;
use App\Domain\Learning\DTO\ReviewOutcome;
use App\Domain\Learning\DTO\TrainingStart;
use App\Domain\Learning\DTO\TrainingStep;
use App\Domain\Learning\Enums\DoneReason;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Exceptions\ReviewSubmissionException;
use App\Domain\Learning\Support\LadderPolicy;
use App\Domain\Learning\Support\NewCardBudget;
use App\Domain\Learning\Support\TrainingScope;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Orchestrates the TWA training screen: which card comes next (respecting the
 * per-session review cap and the learner's new-card budget) and what the learner
 * sees after grading a card. Works for group and personal sessions alike — the
 * difference is the TrainingScope derived from the session (docs/06 §4).
 *
 * The new-card budget applies to PERSONAL sessions only: a teacher-led group lesson
 * (`mode = group_lesson`) gets an unlimited budget, so every member sees the whole
 * lesson whatever their plan, and `quota.new_words_remaining` is `null` there
 * (docs/12 §5.3). Its words are not charged to the personal quota either — see
 * NewWordQuota.
 *
 * `paywall.shown {reason: new_quota_exhausted, source: server}` is recorded the
 * first time per local day a step ends with `done_reason = new_quota_exhausted`
 * (docs/12 §5.4): the client has no event channel, so "ran into the limit" stands
 * for "saw the paywall". Done after the pick, outside any transaction.
 *
 * One request, one read of everything it needs twice (R12g): the learner's plan is resolved once per request and
 * handed to the grade, the budget and the exercise selector; the session's counters come in one statement
 * ({@see CardPicker::stepCounts()}); the quota shown after a pick is the number the picker checked under the lock,
 * less the card it issued ({@see NewCardBudget}), not a second count.
 */
final class TrainingFlowService
{
    public const PAYWALL_CACHE_PREFIX = 'paywall_shown:';

    public function __construct(
        private readonly CardPicker $picker,
        private readonly ReviewSubmissionService $reviews,
        private readonly LearningCache $cache,
        private readonly WordTranslationResolver $translations,
        private readonly LocaleResolver $locales,
        private readonly NewCardBudgetResolver $budgets,
        private readonly LearnerClock $clock,
        private readonly ProductEventRecorder $events,
        private readonly EntitlementResolver $entitlements,
        private readonly ExerciseSelector $selector,
        private readonly ExercisePromptBuilder $prompts,
        private readonly WordAudioService $audio,
        private readonly DeckTitles $deckTitles,
        private readonly CourseCatalogService $catalog,
        private readonly WordExamples $examples,
        private readonly WordHookResolver $hooks,
        private readonly LeechInsight $leeches,
        private readonly LadderPolicy $ladder,
    ) {}

    /**
     * @param  Lesson|null  $lesson  the session's lesson with `stage.course` loaded, when the caller has it already
     *                               ({@see CourseCatalogService::lessonWithContext()}); read here otherwise
     * @return TrainingStart|null null when a lesson-mode session's lesson no longer exists
     */
    public function start(TrainingSession $session, Learner $learner, ?Lesson $lesson = null): ?TrainingStart
    {
        if ($session->mode === TrainingSession::MODE_DAILY_REVIEW) {
            $goal = $this->dailyGoal($learner);

            return new TrainingStart($session, null, $goal, $this->nextStep($session, $learner, $goal));
        }

        // The lesson with its stage and the access facts of its course in ONE query (not lesson, stage, course
        // one after another): what the response shows (stage number, lesson, own-deck title) and nothing more.
        if ($lesson === null || (int) $lesson->id !== (int) $session->lesson_id || ! $lesson->relationLoaded('stage')) {
            $lesson = $this->catalog->lessonWithContext((int) $session->lesson_id);
        }
        if ($lesson === null) {
            return null;
        }
        $session->setRelation('lesson', $lesson);

        $totalWords = $this->cache->lessonWordCount((int) $lesson->id);

        return new TrainingStart(
            $session,
            $lesson,
            $totalWords,
            $this->nextStep($session, $learner, $totalWords),
            $this->deckTitles->forLesson($lesson, $learner),
        );
    }

    /**
     * @param  int|null  $totalWords  lesson scope: active words of the lesson, when the caller already has them
     *                                (cached {@see LearningCache::lessonWordCount()} otherwise); daily: ignored
     * @param  Entitlement|null  $entitlement  the learner's plan when the caller resolved it in this request already
     */
    public function nextStep(TrainingSession $session, Learner $learner, ?int $totalWords = null, ?Entitlement $entitlement = null): TrainingStep
    {
        $now = CarbonImmutable::now();
        $scope = TrainingScope::forSession($session);
        $entitlement ??= $this->entitlements->for($learner, $now);

        // The session's grades (the cap) and the lesson's graduated words (the progress) in one statement.
        $counts = $this->picker->stepCounts((int) $learner->id, (int) $session->id, $scope->isLesson() ? (int) $scope->lessonId : null);
        $reviewCount = $counts['reviews'];

        $progress = $scope->isDaily()
            ? ['done' => $reviewCount, 'total' => $this->dailyGoal($learner)]
            : ['done' => (int) $counts['done'], 'total' => $totalWords ?? $this->cache->lessonWordCount((int) $scope->lessonId)];

        // Group lessons are outside the Free quota (docs/12 §5.3); same lock order either way —
        // the budget is resolved before the picker's transaction.
        $budget = $scope->isGroupLesson() ? NewCardBudget::unlimited() : $this->budgets->budgetFor($learner, $now, $entitlement);

        if ($reviewCount >= max(1, (int) config('twa.training.max_cards_per_session', 100))) {
            return TrainingStep::done(DoneReason::SessionCap, $progress, $budget->remaining((int) $learner->id, $now));
        }

        $pick = $this->picker->pickNext((int) $learner->id, $scope, $budget, $now);

        // What is left of today's new-card budget AFTER this pick: the number the picker checked under the learner
        // lock less the card it just issued (one count a request), or one plain read when the picker never got
        // to the new-card step. Only for display (docs/12 §5.3).
        $remaining = $budget->remaining((int) $learner->id, $now);

        if ($pick->word === null || $pick->repetition === null || $pick->doneReason !== null) {
            $reason = $pick->doneReason ?? $scope->exhaustedReason();
            if ($reason === DoneReason::NewQuotaExhausted) {
                $this->recordPaywallShown($learner, $now);
            }

            return TrainingStep::done($reason, $progress, $remaining);
        }

        $locale = $this->locales->forTrainingSession($session, $learner);
        $translation = $this->translations->resolve($pick->word, $locale);
        $audioUrl = $this->audio->urlFor($pick->word);
        // The example of this serve (docs/06 §12.4): the anchor until graduation, then the rotation.
        $example = $this->examples->pick($pick->word, $pick->repetition);

        // The exercise of this serve (docs/13 §2.5): recomputed from the same inputs by
        // ReviewSubmissionService when the grade comes back — nothing is stored.
        $exercise = $this->selector->select(
            $pick->repetition,
            $pick->word,
            $entitlement,
            LearnerExerciseSettings::fromLearner($learner),
            $session->mode,
            $example,
        );
        $prompt = $this->prompts->build(
            $exercise,
            $pick->word,
            $translation,
            $locale,
            $audioUrl,
            crc32($pick->repetition->repetitions.':'.$pick->word->id),
            $example,
        );

        // The method (docs/06 §12.3, §12.6): the hooks are read only when this serve shows them — the first meeting, a
        // failed card, a hard word — so the usual review costs no lookup; the confusable pair only for a hard word.
        $isLeech = $this->ladder->isLeech($pick->repetition);
        $isHard = (bool) $pick->repetition->is_hard;
        $showsHook = $this->ladder->hooksEnabled
            && ($isLeech || $isHard || $pick->repetition->state !== RepetitionState::Review);

        return new TrainingStep(
            $pick->word,
            $pick->kind,
            $progress,
            // Only learn-ahead cards carry a ticket (docs/04): a card served ahead earlier and
            // due now is a plain due card; submit() accepts it without a token.
            $pick->ahead ? $pick->repetition->served_ahead_token : null,
            $translation,
            newWordsRemaining: $remaining,
            prompt: $prompt,
            audioUrl: $audioUrl,
            example: $example,
            isLeech: $isLeech,
            isHard: $isHard,
            hook: $showsHook ? $this->hooks->for($pick->word, $translation->locale) : null,
            note: $this->ladder->hooksEnabled ? self::text($pick->repetition->note) : null,
            confusedWith: $isLeech ? $this->leeches->confusedWith($pick->repetition, $pick->word) : null,
        );
    }

    private static function text(?string $raw): ?string
    {
        $text = trim((string) $raw);

        return $text === '' ? null : $text;
    }

    private function dailyGoal(Learner $learner): int
    {
        return max(1, (int) $learner->daily_goal);
    }

    /**
     * Once per quota window ({@see LearnerClock::limitWindowUtc()}) — the span the Free quota is counted over,
     * which is what "the wall" is about. Not per local calendar day: right after a timezone change the window
     * is longer than a day and spans two local dates. `Cache::add` wins the race between tabs. The key holds
     * the window's start; its TTL is not "until the window's end" (a later zone change can extend the window
     * past the end seen now) but 3 days, more than the longest window (< 48 h, a little over on a DST change).
     */
    private function recordPaywallShown(Learner $learner, CarbonImmutable $now): void
    {
        $window = $this->clock->limitWindowUtc($learner, $now);

        if (! Cache::add(self::PAYWALL_CACHE_PREFIX.$learner->id.':'.$window->startUtc->format('YmdHis'), true, now()->addDays(3))) {
            return;
        }

        $this->events->record(ProductEventName::PaywallShown, [
            'reason' => DoneReason::NewQuotaExhausted->value,
            'source' => 'server',
        ], learner: $learner);
    }

    /**
     * @param  string|null  $serveToken  `card.serve_token` of the card being graded (required for learn-ahead cards)
     *
     * @throws ReviewSubmissionException
     */
    public function review(
        TrainingSession $session,
        Learner $learner,
        int $wordId,
        int $quality,
        int $timeSpentMs,
        ?string $serveToken = null,
        ?ExerciseType $declared = null,
    ): ReviewOutcome {
        $now = CarbonImmutable::now();
        $entitlement = $this->entitlements->for($learner, $now);

        $repetition = $this->reviews->submit($session, $learner, $wordId, $quality, $timeSpentMs, $serveToken, $now, $entitlement, $declared);

        return new ReviewOutcome($repetition, $this->nextStep($session, $learner, null, $entitlement));
    }

    /**
     * A typed answer to a server-graded exercise (spelling / gap-fill, docs/13 §8.2): the server
     * grades it and the outcome carries the verdict next to the following card.
     *
     * @throws ReviewSubmissionException
     */
    public function answer(
        TrainingSession $session,
        Learner $learner,
        int $wordId,
        string $answer,
        int $timeSpentMs,
        ?string $serveToken = null,
        ?ExerciseType $declared = null,
    ): ReviewOutcome {
        $now = CarbonImmutable::now();
        $entitlement = $this->entitlements->for($learner, $now);

        $accepted = $this->reviews->submitAnswer($session, $learner, $wordId, $answer, $timeSpentMs, $serveToken, $now, $entitlement, $declared);

        return new ReviewOutcome(
            $accepted->repetition,
            $this->nextStep($session, $learner, null, $entitlement),
            $accepted->verdict,
            $this->revealed($accepted),
        );
    }

    /**
     * What the answered meaning / reverse / audio card was sent without (docs/06 §12.2). Read after the grade is committed: the
     * hook comes from the cache and the confusable pair from one indexed query, neither belongs under the row locks.
     */
    private function revealed(AcceptedReview $accepted): ?RevealedCard
    {
        $word = $accepted->word;
        $translation = $accepted->translation;
        if ($word === null || $translation === null) {
            return null;
        }

        $reverse = $accepted->exercise === ExerciseType::Reverse;

        return new RevealedCard(
            word: $word->word,
            translation: $translation->text,
            // The right option exactly as it stands among the options: reverse — the word; meaning / audio — the translation.
            correct: $reverse ? trim($word->word) : trim($translation->text),
            example: $accepted->example,
            transcription: $word->transcription,
            audioUrl: $this->audio->urlFor($word),
            hook: $accepted->showedHook ? $this->hooks->for($word, $translation->locale) : null,
            note: $this->ladder->hooksEnabled ? self::text($accepted->repetition->note) : null,
            confusedWith: $accepted->wasLeech ? $this->leeches->confusedWith($accepted->repetition, $word) : null,
        );
    }
}
