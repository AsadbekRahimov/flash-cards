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
use App\Domain\Learning\DTO\CardPick;
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
use App\Domain\Learning\Support\LearningPolicy;
use App\Domain\Learning\Support\NewCardBudget;
use App\Domain\Learning\Support\SprintLimits;
use App\Domain\Learning\Support\SprintPolicy;
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
 *
 * The sprint (docs/06 §13.2, `learning.sprint.*`): a daily session is a round of at most `due_cards` day-based reviews
 * and `new_words` new words with their learning steps. The round's counters ride in the same counters statement; the
 * picker gets them as {@see SprintLimits}; the progress is "grades in this session / the round's estimated size"
 * ({@see sprintProgress()}) and the session ends with `sprint_complete` + `can_continue` when another round would have
 * cards. Lesson sessions are not rounds.
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
        private readonly SprintPolicy $sprint,
        private readonly LearningPolicy $learning,
        private readonly TrainingSessionService $sessions,
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
            $step = $this->nextStep($session, $learner, $goal);

            // `total_words` of a daily session: the round's size with a sprint, the daily goal without one.
            return new TrainingStart($session, null, $this->sprint->enabled ? $step->progress['total'] : $goal, $step);
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

        // The session's grades (the cap), the lesson's graduated words (the progress) and, for a round, its counters
        // in one statement.
        $sprintOn = $scope->isDaily() && $this->sprint->enabled;
        $startedAt = CarbonImmutable::instance($session->started_at);
        $counts = $this->picker->stepCounts(
            (int) $learner->id,
            (int) $session->id,
            $scope->isLesson() ? (int) $scope->lessonId : null,
            $sprintOn ? ['started_at' => $startedAt, 'due_cap' => $this->sprint->dueCards, 'now' => $now] : null,
        );
        $reviewCount = $counts['reviews'];
        $limits = null;
        if ($sprintOn) {
            // The review debt (docs/06 §13.2): more day-based cards due than one round takes (those graded in this round
            // included, so the answer holds for the whole round) - the backlog is cleared first, no new words meanwhile.
            $debt = $this->sprint->debtHoldsNew && $this->sprint->dueCards !== null && $this->sprint->dueCards < $counts['due_left'] + $counts['due_done'];
            $limits = new SprintLimits(
                $startedAt,
                $debt ? 0 : $this->sprint->newWords,
                $counts['new_done'],
                $this->sprint->dueCards === null ? null : $this->sprint->dueCards - $counts['due_done'],
            );
        }

        $progress = match (true) {
            $sprintOn => ['done' => $reviewCount, 'total' => $reviewCount],
            $scope->isDaily() => ['done' => $reviewCount, 'total' => $this->dailyGoal($learner)],
            default => ['done' => (int) $counts['done'], 'total' => $totalWords ?? $this->cache->lessonWordCount((int) $scope->lessonId)],
        };

        // Group lessons are outside the Free quota (docs/12 §5.3); same lock order either way —
        // the budget is resolved before the picker's transaction.
        $budget = $scope->isGroupLesson() ? NewCardBudget::unlimited() : $this->budgets->budgetFor($learner, $now, $entitlement);

        if ($reviewCount >= max(1, (int) config('twa.training.max_cards_per_session', 100))) {
            $this->completed($session, $reviewCount, $now);

            return TrainingStep::done(DoneReason::SessionCap, $progress, $budget->remaining((int) $learner->id, $now));
        }

        $pick = $this->picker->pickNext((int) $learner->id, $scope, $budget, $now, $limits);

        // What is left of today's new-card budget AFTER this pick: the number the picker checked under the learner
        // lock less the card it just issued (one count a request), or one plain read when the picker never got
        // to the new-card step. Only for display (docs/12 §5.3).
        $remaining = $budget->remaining((int) $learner->id, $now);

        if ($pick->word === null || $pick->repetition === null || $pick->doneReason !== null) {
            $reason = $pick->doneReason ?? $scope->exhaustedReason();
            if ($reason === DoneReason::NewQuotaExhausted) {
                $this->recordPaywallShown($learner, $now);
            }
            $this->completed($session, $reviewCount, $now);

            return TrainingStep::done($reason, $progress, $remaining, $pick->canContinue);
        }

        if ($limits !== null) {
            $progress = $this->sprintProgress($counts, $limits, $pick, $scope->includesNew() ? $remaining : 0);
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
            $audioUrl !== null,
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

    /**
     * No more cards for a personal session that was played (docs/06 §13.2): stamped once, so the next open of the daily
     * session starts a fresh round and the scheduler closes this one as `finished`. A session without grades is left alone.
     */
    private function completed(TrainingSession $session, int $reviewCount, CarbonImmutable $now): void
    {
        if ($reviewCount > 0 && $session->learner_id !== null) {
            $this->sessions->markCompleted($session, $now);
        }
    }

    private function dailyGoal(Learner $learner): int
    {
        return max(1, (int) $learner->daily_goal);
    }

    /**
     * The round's progress (docs/06 §13.2): `done` = grades in this session; `total` = done + what the round still owes
     * after this pick — the due reviews it may still take (counted up to the cap), the new words it may still introduce
     * (within the day's quota, and none once the picker has seen the course run dry) times the learning steps each one
     * costs, and the grades the cards in minute steps still need. The estimate never falls below `done`, so the bar never
     * shows "35 / 20"; a failed step adds its repeat to `total`, an "already knew it" takes a step away from it.
     *
     * @param  array{reviews:int, done:int|null, due_done:int, new_done:int, pending:int, due_left:int}  $counts  counted BEFORE the pick
     * @param  int|null  $quotaRemaining  the day's new cards left after this pick; null = unlimited; 0 when the scope has no
     *                                    source of new words (a daily session without a course)
     * @return array{done:int, total:int}
     */
    private function sprintProgress(array $counts, SprintLimits $limits, CardPick $pick, ?int $quotaRemaining): array
    {
        $done = $counts['reviews'];
        $steps = count($this->learning->learningStepsMinutes);
        $pickedNew = $pick->kind === 'new';

        if ($pick->newExhausted) {
            // The picker looked and the course has no unseen word left: the round owes none, whatever its limit says.
            $newLeft = 0;
        } elseif ($limits->newLimit === null) {
            // No round limit: the day's quota is the only bound; without one either, nothing to estimate beyond the pick.
            $newLeft = max(0, ($quotaRemaining ?? 0));
        } else {
            $newLeft = max(0, $limits->newRemaining() - ($pickedNew ? 1 : 0));
            if ($quotaRemaining !== null) {
                $newLeft = min($newLeft, $quotaRemaining);
            }
        }
        // The new row of this pick was not there when the counters ran: it owes the full learning steps.
        $pending = $counts['pending'] + ($pickedNew ? $steps : 0);
        // Due cards are counted up to the cap (+ 1); the round may take only what is left of it.
        $dueLeft = $limits->dueRemaining === null ? $counts['due_left'] : min($counts['due_left'], max(0, $limits->dueRemaining));

        return ['done' => $done, 'total' => max($done, $done + $dueLeft + $newLeft * $steps + $pending)];
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
