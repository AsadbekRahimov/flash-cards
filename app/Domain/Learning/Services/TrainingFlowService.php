<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Content\Services\DeckTitles;
use App\Domain\Content\Services\WordAudioService;
use App\Domain\Content\Services\WordTranslationResolver;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learning\Contracts\NewCardBudgetResolver;
use App\Domain\Learning\DTO\LearnerExerciseSettings;
use App\Domain\Learning\DTO\ReviewOutcome;
use App\Domain\Learning\DTO\TrainingStart;
use App\Domain\Learning\DTO\TrainingStep;
use App\Domain\Learning\Enums\DoneReason;
use App\Domain\Learning\Exceptions\ReviewSubmissionException;
use App\Domain\Learning\Support\NewCardBudget;
use App\Domain\Learning\Support\TrainingScope;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Learner;
use App\Models\TrainingReview;
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
    ) {}

    /**
     * @return TrainingStart|null null when a lesson-mode session's lesson no longer exists
     */
    public function start(TrainingSession $session, Learner $learner): ?TrainingStart
    {
        if ($session->mode === TrainingSession::MODE_DAILY_REVIEW) {
            $goal = $this->dailyGoal($learner);

            return new TrainingStart($session, null, $goal, $this->nextStep($session, $learner, $goal));
        }

        $session->loadMissing('lesson.stage.course');

        $lesson = $session->lesson;
        if ($lesson === null) {
            return null;
        }

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
     * @param  int|null  $totalWords  lesson scope: active words of the lesson (cached); daily: ignored
     */
    public function nextStep(TrainingSession $session, Learner $learner, ?int $totalWords = null): TrainingStep
    {
        $now = CarbonImmutable::now();
        $scope = TrainingScope::forSession($session);

        $reviewCount = TrainingReview::query()
            ->where('training_session_id', $session->id)
            ->where('learner_id', $learner->id)
            ->count();

        $progress = $scope->isDaily()
            ? ['done' => $reviewCount, 'total' => $this->dailyGoal($learner)]
            : $this->picker->progress((int) $learner->id, (int) $scope->lessonId, $totalWords);

        // Group lessons are outside the Free quota (docs/12 §5.3); same lock order either way —
        // the budget is resolved before the picker's transaction.
        $budget = $scope->isGroupLesson() ? NewCardBudget::unlimited() : $this->budgets->budgetFor($learner, $now);

        if ($reviewCount >= max(1, (int) config('twa.training.max_cards_per_session', 100))) {
            return TrainingStep::done(DoneReason::SessionCap, $progress, $budget->remaining((int) $learner->id, $now));
        }

        $pick = $this->picker->pickNext((int) $learner->id, $scope, $budget, $now);

        // What is left of today's new-card budget AFTER this pick (a freshly served new card is already
        // spent). A plain read outside the picker's transaction: it is only for display (docs/12 §5.3).
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

        // The exercise of this serve (docs/13 §2.5): recomputed from the same inputs by
        // ReviewSubmissionService when the grade comes back — nothing is stored.
        $exercise = $this->selector->select(
            $pick->repetition,
            $pick->word,
            $this->entitlements->for($learner, $now),
            LearnerExerciseSettings::fromLearner($learner),
            $session->mode,
        );
        $prompt = $this->prompts->build(
            $exercise,
            $pick->word,
            $translation,
            $locale,
            $audioUrl,
            crc32($pick->repetition->repetitions.':'.$pick->word->id),
        );

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
        );
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
    ): ReviewOutcome {
        $repetition = $this->reviews->submit($session, $learner, $wordId, $quality, $timeSpentMs, $serveToken);

        return new ReviewOutcome($repetition, $this->nextStep($session, $learner));
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
    ): ReviewOutcome {
        $accepted = $this->reviews->submitAnswer($session, $learner, $wordId, $answer, $timeSpentMs, $serveToken);

        return new ReviewOutcome($accepted->repetition, $this->nextStep($session, $learner), $accepted->verdict);
    }
}
