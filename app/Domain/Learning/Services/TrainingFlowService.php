<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Content\Services\WordTranslationResolver;
use App\Domain\Learning\Contracts\NewCardBudgetResolver;
use App\Domain\Learning\DTO\ReviewOutcome;
use App\Domain\Learning\DTO\TrainingStart;
use App\Domain\Learning\DTO\TrainingStep;
use App\Domain\Learning\Enums\DoneReason;
use App\Domain\Learning\Exceptions\ReviewSubmissionException;
use App\Domain\Learning\Support\TrainingScope;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Learner;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;

/**
 * Orchestrates the TWA training screen: which card comes next (respecting the
 * per-session review cap and the learner's new-card budget) and what the learner
 * sees after grading a card. Works for group and personal sessions alike — the
 * difference is the TrainingScope derived from the session (docs/06 §4).
 */
final class TrainingFlowService
{
    public function __construct(
        private readonly CardPicker $picker,
        private readonly ReviewSubmissionService $reviews,
        private readonly LearningCache $cache,
        private readonly WordTranslationResolver $translations,
        private readonly LocaleResolver $locales,
        private readonly NewCardBudgetResolver $budgets,
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

        $session->loadMissing('lesson.stage');

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

        if ($reviewCount >= max(1, (int) config('twa.training.max_cards_per_session', 100))) {
            return TrainingStep::done(DoneReason::SessionCap, $progress);
        }

        $pick = $this->picker->pickNext(
            (int) $learner->id,
            $scope,
            $this->budgets->budgetFor($learner, $now),
            $now,
        );

        if ($pick->word === null || $pick->repetition === null || $pick->doneReason !== null) {
            return TrainingStep::done($pick->doneReason ?? $scope->exhaustedReason(), $progress);
        }

        return new TrainingStep(
            $pick->word,
            $pick->kind,
            $progress,
            // Only learn-ahead cards carry a ticket (docs/04): a card served ahead earlier and
            // due now is a plain due card; submit() accepts it without a token.
            $pick->ahead ? $pick->repetition->served_ahead_token : null,
            $this->translations->resolve($pick->word, $this->localeFor($session, $learner)),
        );
    }

    /**
     * Language of the card translation: the group's locale in a group session (the whole class
     * sees the same language), the learner's own interface language in a personal one.
     */
    private function localeFor(TrainingSession $session, Learner $learner): string
    {
        if ($session->mode === TrainingSession::MODE_GROUP_LESSON) {
            $session->loadMissing('group');
            if ($session->group !== null) {
                return $this->locales->forGroup($session->group);
            }
        }

        return $this->locales->forLearner($learner);
    }

    private function dailyGoal(Learner $learner): int
    {
        return max(1, (int) $learner->daily_goal);
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
}
