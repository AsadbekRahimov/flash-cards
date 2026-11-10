<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Content\Services\WordExamples;
use App\Domain\Content\Services\WordTranslationResolver;
use App\Domain\Learners\Services\DailyStatsService;
use App\Domain\Learning\DTO\AcceptedReview;
use App\Domain\Learning\DTO\LearnerExerciseSettings;
use App\Domain\Learning\DTO\SpellingVerdict;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Exceptions\ReviewSubmissionException;
use App\Domain\Learning\Support\LadderPolicy;
use App\Domain\Learning\Support\LearningPolicy;
use App\Domain\Learning\Support\RepetitionWithWord;
use App\Domain\Learning\Support\TrainingScope;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Learner;
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
 * (409 `card_not_due`). Then the learner's scheduler (SM-2 or FSRS, SchedulerResolver) is applied, the grade is logged in
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
 * Exercises (docs/13 §2.5, §8.2; docs/06 §4.3, §12): the exercise the card was served with is recomputed here from the
 * same inputs ExerciseSelector used for `/next` (the locked, not yet graded repetition row, the word, the plan, the
 * learner's settings, the session mode, the example of this serve) — nothing is remembered between the two requests.
 * Who grades what (docs/06 §12.2):
 *   - flash: the learner's own `quality`; `answer` is refused (422 `answer_not_accepted`, or 422 `exercise_pro_required`
 *     — a paywall reason — when the plan has no typed exercise at all);
 *   - reverse / audio / pretest: the picked option as `answer`, graded by the server against the right option
 *     (4 / 2; the pretest 5 / 2). A legacy `quality` is accepted while `learning.objective_grading.accept_legacy_quality`
 *     is on, normalised to 4 (pass) / 2 (fail) — never the learner's 5; off → 422 `quality_not_accepted`;
 *   - spelling / gap-fill: `answer`, graded with SpellingGrader against the word or the form in the example of this
 *     serve; `quality` is refused (422 `quality_not_accepted`).
 * The plan and the settings are read BEFORE the transaction: the review transaction holds
 * `training_sessions → word_repetitions → learner_daily_stats` only.
 *
 * The ladder (docs/06 §12.1): a lapse of a `review` card writes `meta.ladder_floor` = the rung below the one it failed at,
 * a pass at the top clears it; `meta.last_exercise` remembers the exercise of this grade. Both ride in the scheduler's
 * UPDATE — no extra statement. The self-grade mismatch metric (docs/25 "metrics"): a wrong objectively graded answer that
 * follows a flash self-grade of 4 or 5 on the same word records `review.self_grade_mismatch` AFTER the commit.
 *
 * Statements of the transaction (R12g): the session row `FOR UPDATE` (status and expiry only - the mode and the
 * lesson of a session never change, the model in hand has them), the repetition WITH its word in one joined read
 * locked `FOR UPDATE OF word_repetitions` ({@see RepetitionWithWord}: the `words` row is shared by every learner
 * and must not be locked), the scheduler's UPDATE, the `training_reviews` INSERT (a plain insert: the row is not
 * read back), then DailyStatsService. Nothing of the SRS itself changed.
 */
final class ReviewSubmissionService
{
    public const ANSWER_MAX_LENGTH = 100;

    public const CHOICE_PASS_QUALITY = 4;

    public const CHOICE_FAIL_QUALITY = 2;

    public function __construct(
        private readonly SchedulerResolver $schedulers,
        private readonly LearningPolicy $policy,
        private readonly LadderPolicy $ladder,
        private readonly DailyStatsService $dailyStats,
        private readonly EntitlementResolver $entitlements,
        private readonly ExerciseSelector $selector,
        private readonly SpellingGrader $grader,
        private readonly GapFillBuilder $gapFill,
        private readonly ExercisePromptBuilder $prompts,
        private readonly LocaleResolver $locales,
        private readonly WordTranslationResolver $translations,
        private readonly WordExamples $examples,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * A self-graded (flash) card — or a legacy client's grade of a four-option card (docs/06 §12.2).
     *
     * @param  string|null  $serveToken  `card.serve_token` echoed by the client; ignored for due cards
     * @param  Entitlement|null  $entitlement  the learner's plan when the caller read it already (TrainingFlowService
     *                                         reads it once per request for the grade and the next card)
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
        ?Entitlement $entitlement = null,
    ): WordRepetition {
        return $this->accept($session, $learner, $wordId, $quality, null, $timeSpentMs, $serveToken, $now, $entitlement)->repetition;
    }

    /**
     * An answer the server grades: the typed text of spelling / gap-fill, or the picked option of reverse / audio / pretest.
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
        ?Entitlement $entitlement = null,
    ): AcceptedReview {
        return $this->accept($session, $learner, $wordId, null, $answer, $timeSpentMs, $serveToken, $now, $entitlement);
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
        ?Entitlement $entitlement,
    ): AcceptedReview {
        $now ??= CarbonImmutable::now();
        $entitlement ??= $this->entitlements->for($learner, $now);
        $settings = LearnerExerciseSettings::fromLearner($learner);
        // The learner's arm of the SRS A/B (docs/13 §8.3) — decided from the row already in hand, outside the transaction.
        $scheduler = $this->schedulers->for($learner);
        $scope = TrainingScope::forSession($session);
        $locale = $this->locales->forTrainingSession($session, $learner);

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
            $scheduler,
            $scope,
            $locale,
        ): AcceptedReview {
            // The lock on the session row orders this grade against `/finish` (docs/06 §5.1): what is read under it
            // is only what can change - the status and the expiry. Mode and lesson are fixed at creation; the
            // course_id of a daily_review session may change (openPersonal), but neither the scope nor the exercise pick reads it.
            /** @var TrainingSession|null $lockedSession */
            $lockedSession = TrainingSession::query()
                ->select(['id', 'status', 'expires_at'])
                ->lockForUpdate()
                ->find($session->id);

            if ($lockedSession === null || ! $lockedSession->isLiveAt($now)) {
                throw new ReviewSubmissionException(
                    410,
                    'session_closed',
                    'This training session is closed.',
                );
            }

            // The repetition and its word in one statement; the word rides along unfiltered (the scope check below
            // tells `out_of_scope` from `card_not_started`), the lock covers the repetition row alone.
            /** @var WordRepetition|null $repetition */
            $repetition = RepetitionWithWord::lockRepetition(
                RepetitionWithWord::withWord(
                    WordRepetition::query()
                        ->where('word_repetitions.learner_id', $learner->id)
                        ->where('word_repetitions.word_id', $wordId),
                ),
            )->first();

            $word = $repetition === null ? null : RepetitionWithWord::hydrate($repetition);

            if ($repetition === null || $word === null) {
                throw new ReviewSubmissionException(
                    409,
                    'card_not_started',
                    'Card was never served to this learner.',
                );
            }

            if (! $scope->contains($word)) {
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

            // The exercise this serve was shown with — from the still ungraded row (docs/13 §8.2), with the example of this
            // serve (docs/06 §12.4). A four-option pick without enough distractors was shown as flash — record what the client saw.
            $example = $this->examples->pick($word, $repetition);
            $translation = $this->translations->resolve($word, $locale);
            $exercise = $this->prompts->effectiveType(
                $this->selector->select($repetition, $word, $entitlement, $settings, $session->mode, $example),
                $word,
                $locale,
                crc32($repetition->repetitions.':'.$word->id),
                $example,
            );
            $verdict = null;

            if ($answer !== null) {
                $verdict = $this->gradeAnswer($exercise, $word, $answer, $entitlement, $example, $this->prompts->correctOption($exercise, $word, $translation));
                $quality = $verdict->quality;
            } else {
                $quality = $this->acceptQuality($exercise, (int) $quality);
            }

            // First grade of a card that was only served so far (docs/12 §3.3).
            $isNewCard = $repetition->state === RepetitionState::New;
            $intervalBefore = (int) $repetition->interval_days;

            $mismatch = $this->stampLadder($repetition, $exercise, $quality);

            $scheduler->schedule($repetition, $quality, $now);

            DB::table('training_reviews')->insert([
                'training_session_id' => $session->id,
                'learner_id' => $learner->id,
                'word_id' => $wordId,
                'quality' => $quality,
                'exercise' => $exercise->value,
                'answer_text' => $answer === null ? null : mb_substr($answer, 0, self::ANSWER_MAX_LENGTH),
                'scheduler' => $scheduler->key()->value,
                'interval_before_days' => $intervalBefore,
                'time_spent_ms' => $timeSpentMs,
                'created_at' => $now,
            ]);

            $this->dailyStats->recordReview($learner, $quality, $timeSpentMs, $isNewCard, $now);

            if ($mismatch !== null) {
                // The metric of the self-grade's honesty (docs/25): one cheap insert, after the locks are released.
                DB::afterCommit(fn () => $this->events->record(ProductEventName::ReviewSelfGradeMismatch, [
                    'word_id' => $wordId,
                    'exercise' => $exercise->value,
                    'previous_quality' => $mismatch,
                    'interval_before_days' => $intervalBefore,
                ], learner: $learner));
            }

            return new AcceptedReview($repetition, $exercise, $verdict);
        });
    }

    /**
     * The `quality` a client sent, as the method accepts it (docs/06 §12.2).
     *
     * @throws ReviewSubmissionException when the exercise is graded by the server
     */
    private function acceptQuality(ExerciseType $exercise, int $quality): int
    {
        if ($exercise->selfGraded()) {
            return $quality;
        }

        if ($exercise->isChoice() && ! $exercise->serverGraded() && $this->ladder->acceptLegacyChoiceQuality) {
            // An older Mini App graded the pick itself: a pass is a pass, a fail a fail — the learner's own "easy" is not.
            return $quality >= SpacedRepetitionEngine::PASS_QUALITY ? self::CHOICE_PASS_QUALITY : self::CHOICE_FAIL_QUALITY;
        }

        throw new ReviewSubmissionException(
            422,
            'quality_not_accepted',
            'This card is graded by the server: send `answer`, not `quality`.',
        );
    }

    /**
     * @param  string  $correctOption  the right option of a four-option card (reverse: the word; audio / pretest: the translation)
     *
     * @throws ReviewSubmissionException when the card's exercise is the learner's own grade
     */
    private function gradeAnswer(ExerciseType $exercise, Word $word, string $answer, Entitlement $entitlement, ?string $example, string $correctOption): SpellingVerdict
    {
        if ($exercise->selfGraded()) {
            if (! $this->selector->typedAvailableToPlan($entitlement)) {
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

        if ($exercise->isChoice()) {
            $expected = $this->grader->normalize($correctOption);
            $picked = $this->grader->normalize($answer);
            $right = $expected !== '' && $expected === $picked;
            $pass = $exercise === ExerciseType::Pretest ? SpacedRepetitionEngine::EASY_QUALITY : self::CHOICE_PASS_QUALITY;

            return new SpellingVerdict($right ? $pass : self::CHOICE_FAIL_QUALITY, $right ? 0 : 1, $expected, $picked);
        }

        $expected = $word->word;
        if ($exercise === ExerciseType::GapFill) {
            $gap = $this->gapFill->fromText($word->word, $example ?? $word->example);
            if ($gap !== null) {
                $expected = $gap->expected;
            }
        }

        return $this->grader->grade($expected, $answer);
    }

    /**
     * The ladder marks of this grade on the row (docs/06 §12.1), set BEFORE the scheduler saves it so they ride in its UPDATE.
     *
     * @return int|null the previous self-grade when this grade is the mismatch the metric counts (docs/25), else null
     */
    private function stampLadder(WordRepetition $repetition, ExerciseType $exercise, int $quality): ?int
    {
        $meta = is_array($repetition->meta) ? $repetition->meta : [];
        $previousExercise = $meta[LadderPolicy::LAST_EXERCISE_META] ?? null;
        $previousQuality = $repetition->getAttribute('last_quality');
        $failed = $quality < SpacedRepetitionEngine::PASS_QUALITY;

        $mismatch = $failed
            && ! $exercise->selfGraded()
            && $previousExercise === ExerciseType::Flash->value
            && is_numeric($previousQuality)
            && (int) $previousQuality >= self::CHOICE_PASS_QUALITY
            ? (int) $previousQuality
            : null;

        if ($repetition->state === RepetitionState::Review && $this->ladder->ladderEnabled) {
            $rung = $exercise->rung();
            if ($failed) {
                $floor = max(LadderPolicy::RUNG_FLASH, $rung - 1);
                if ($floor > LadderPolicy::RUNG_FLASH) {
                    $meta[LadderPolicy::FLOOR_META] = $floor;
                } else {
                    unset($meta[LadderPolicy::FLOOR_META]);
                }
            } elseif (isset($meta[LadderPolicy::FLOOR_META]) && $this->ladder->maturityRung($repetition) >= $this->ladder->floorRung($repetition)) {
                // The interval has caught up with the floor: nothing left to remember.
                unset($meta[LadderPolicy::FLOOR_META]);
            }
        }

        $meta[LadderPolicy::LAST_EXERCISE_META] = $exercise->value;
        $repetition->meta = $meta;

        return $mismatch;
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
