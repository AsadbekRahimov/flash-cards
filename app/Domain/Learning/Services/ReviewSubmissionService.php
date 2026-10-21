<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Learners\Services\DailyStatsService;
use App\Domain\Learning\DTO\AcceptedReview;
use App\Domain\Learning\DTO\LearnerExerciseSettings;
use App\Domain\Learning\DTO\SpellingVerdict;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Exceptions\ReviewSubmissionException;
use App\Domain\Learning\Support\LearningPolicy;
use App\Domain\Learning\Support\TrainingScope;
use App\Models\Learner;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Atomically applies a learner's grade to a card inside an open session.
 *
 * Checks, in order (docs/06 §5.1): session open (410 `session_closed`) → the
 * repetition row exists (409 `card_not_started`) → the word is inside the
 * session's TrainingScope (422 `out_of_scope`) → the card may be graded now
 * (409 `card_not_due`). Then SM-2 is applied, the grade is logged in
 * `training_reviews` and the day's aggregate is updated through
 * DailyStatsService::recordReview — all in ONE transaction, so a failure in any
 * step leaves no partial state.
 *
 * Double-submit protection: a card may be graded only if it is due
 * (`next_review_at <= now`) or if CardPicker served it early (learn-ahead)
 * and the client presents that serve's one-time ticket (`serve_token`, the
 * row's `served_ahead_token`). Every accepted review moves `next_review_at`
 * into the future and clears the ticket, so replaying the same request —
 * even after the engine re-served the same card with a new ticket in the
 * same response — is rejected with 409 `card_not_due`.
 *
 * Exercises (docs/13 §2.5, §8.2; docs/06 §4.3): the exercise the card was served with is
 * recomputed here from the same inputs ExerciseSelector used for `/next` (the locked, not yet
 * graded repetition row, the word, the plan, the learner's settings, the session mode) — nothing
 * is remembered between the two requests. A client-graded exercise (flash / reverse / audio)
 * takes `quality` and refuses `answer` (422 `answer_not_accepted`; 422 `exercise_pro_required`
 * — a paywall reason — when the plan has no server-graded exercise at all); a server-graded one
 * (spelling / gap-fill) takes `answer`, grades it with SpellingGrader and refuses `quality`
 * (422 `quality_not_accepted`). The plan and the settings are read BEFORE the transaction: the
 * review transaction holds `training_sessions → word_repetitions → learner_daily_stats` only.
 */
final class ReviewSubmissionService
{
    public const ANSWER_MAX_LENGTH = 100;

    public function __construct(
        private readonly SpacedRepetitionEngine $engine,
        private readonly LearningPolicy $policy,
        private readonly DailyStatsService $dailyStats,
        private readonly EntitlementResolver $entitlements,
        private readonly ExerciseSelector $selector,
        private readonly SpellingGrader $grader,
        private readonly GapFillBuilder $gapFill,
    ) {}

    /**
     * A self-graded (flash) or client-graded (reverse / audio) card.
     *
     * @param  string|null  $serveToken  `card.serve_token` echoed by the client; ignored for due cards
     *
     * @throws ReviewSubmissionException
     */
    public function submit(
        TrainingSession $session,
        Learner $learner,
        int $wordId,
        int $quality,
        int $timeSpentMs,
        ?string $serveToken = null,
        ?CarbonImmutable $now = null,
    ): WordRepetition {
        return $this->accept($session, $learner, $wordId, $quality, null, $timeSpentMs, $serveToken, $now)->repetition;
    }

    /**
     * A typed answer to a server-graded card (spelling / gap-fill): the server sets the quality.
     *
     * @throws ReviewSubmissionException
     */
    public function submitAnswer(
        TrainingSession $session,
        Learner $learner,
        int $wordId,
        string $answer,
        int $timeSpentMs,
        ?string $serveToken = null,
        ?CarbonImmutable $now = null,
    ): AcceptedReview {
        return $this->accept($session, $learner, $wordId, null, $answer, $timeSpentMs, $serveToken, $now);
    }

    /**
     * @param  int|null  $quality  exactly one of $quality / $answer is non-null
     *
     * @throws ReviewSubmissionException
     */
    private function accept(
        TrainingSession $session,
        Learner $learner,
        int $wordId,
        ?int $quality,
        ?string $answer,
        int $timeSpentMs,
        ?string $serveToken,
        ?CarbonImmutable $now,
    ): AcceptedReview {
        $now ??= CarbonImmutable::now();
        $entitlement = $this->entitlements->for($learner, $now);
        $settings = LearnerExerciseSettings::fromLearner($learner);

        /** @var ConnectionInterface $db */
        $db = DB::connection();

        return $db->transaction(function () use (
            $session,
            $learner,
            $wordId,
            $quality,
            $answer,
            $timeSpentMs,
            $serveToken,
            $now,
            $entitlement,
            $settings,
        ): AcceptedReview {
            /** @var TrainingSession|null $lockedSession */
            $lockedSession = TrainingSession::query()
                ->lockForUpdate()
                ->find($session->id);

            if ($lockedSession === null || ! $lockedSession->isLiveAt($now)) {
                throw new ReviewSubmissionException(
                    410,
                    'session_closed',
                    'This training session is closed.',
                );
            }

            /** @var WordRepetition|null $repetition */
            $repetition = WordRepetition::query()
                ->where('learner_id', $learner->id)
                ->where('word_id', $wordId)
                ->with('word')
                ->lockForUpdate()
                ->first();

            if ($repetition === null || $repetition->word === null) {
                throw new ReviewSubmissionException(
                    409,
                    'card_not_started',
                    'Card was never served to this learner.',
                );
            }

            $word = $repetition->word;

            if (! TrainingScope::forSession($lockedSession)->contains($word)) {
                throw new ReviewSubmissionException(
                    422,
                    'out_of_scope',
                    'Word is outside the scope of this training session.',
                );
            }

            if (! $this->isAcceptable($repetition, $serveToken, $now)) {
                throw new ReviewSubmissionException(
                    409,
                    'card_not_due',
                    'This card was already reviewed and is not due yet.',
                );
            }

            // The exercise this serve was shown with — from the still ungraded row (docs/13 §8.2).
            $exercise = $this->selector->select($repetition, $word, $entitlement, $settings, $lockedSession->mode);
            $verdict = null;

            if ($answer !== null) {
                $verdict = $this->gradeAnswer($exercise, $word, $answer, $entitlement);
                $quality = $verdict->quality;
            } elseif ($exercise->serverGraded()) {
                throw new ReviewSubmissionException(
                    422,
                    'quality_not_accepted',
                    'This card is graded by the server: send `answer`, not `quality`.',
                );
            }

            // First grade of a card that was only served so far (docs/12 §3.3).
            $isNewCard = $repetition->state === RepetitionState::New;
            $intervalBefore = (int) $repetition->interval_days;

            $this->engine->applyReview($repetition, (int) $quality, $now);

            TrainingReview::query()->create([
                'training_session_id' => $session->id,
                'learner_id' => $learner->id,
                'word_id' => $wordId,
                'quality' => $quality,
                'exercise' => $exercise,
                'answer_text' => $answer === null ? null : mb_substr($answer, 0, self::ANSWER_MAX_LENGTH),
                'interval_before_days' => $intervalBefore,
                'time_spent_ms' => $timeSpentMs,
                'created_at' => $now,
            ]);

            $this->dailyStats->recordReview($learner, (int) $quality, $timeSpentMs, $isNewCard, $now);

            return new AcceptedReview($repetition, $exercise, $verdict);
        });
    }

    /**
     * @throws ReviewSubmissionException when the card's exercise is not graded by the server
     */
    private function gradeAnswer(ExerciseType $exercise, Word $word, string $answer, Entitlement $entitlement): SpellingVerdict
    {
        if (! $exercise->serverGraded()) {
            if (! $entitlement->can('exercise.spelling') && ! $entitlement->can('exercise.gap_fill')) {
                throw new ReviewSubmissionException(
                    422,
                    'exercise_pro_required',
                    'Typed answers (spelling, gap-fill) are a Pro feature.',
                );
            }

            throw new ReviewSubmissionException(
                422,
                'answer_not_accepted',
                'This card is graded by the client: send `quality`, not `answer`.',
            );
        }

        $expected = $word->word;
        if ($exercise === ExerciseType::GapFill) {
            $gap = $this->gapFill->build($word);
            if ($gap !== null) {
                $expected = $gap->expected;
            }
        }

        return $this->grader->grade($expected, $answer);
    }

    private function isAcceptable(WordRepetition $repetition, ?string $serveToken, CarbonImmutable $now): bool
    {
        if (! $repetition->next_review_at->isAfter($now)) {
            return true;
        }

        if (! $repetition->state->isIntraSession()) {
            return false;
        }

        // Learn-ahead: the client must present the ticket of the serve it is
        // answering. A missing, stale or foreign token means this is a replay
        // (or an old client) — the engine clears the token on every answer.
        if ($serveToken === null || $serveToken === '' || $repetition->served_ahead_token === null) {
            return false;
        }
        if (! hash_equals($repetition->served_ahead_token, $serveToken)) {
            return false;
        }

        // The ticket is valid only inside the configured window.
        return ! $repetition->next_review_at->isAfter($now->addMinutes($this->policy->learnAheadMinutes));
    }
}
