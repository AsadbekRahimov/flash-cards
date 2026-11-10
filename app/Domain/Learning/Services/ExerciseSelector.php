<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Learning\DTO\LearnerExerciseSettings;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Support\LadderPolicy;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;

/**
 * Picks the exercise a card is served with (docs/13 §2.5, docs/06 §4.3, §12.1).
 *
 * Deterministic and stateless: the same (repetition row, word, plan, settings, session mode, example) always gives the
 * same type, so `POST /review` recomputes it from the same inputs instead of remembering what `/next` served
 * (docs/13 §8.2). The inputs that change on an accepted review (`state`, `repetitions`, `interval_days`, `meta`) change
 * only after the grade is applied, and a card cannot be graded twice for one serve (docs/06 §5.2).
 *
 * The ladder (docs/25 §3.1), `learning.ladder.enabled`:
 *   - a `new` card with the pretest on → pretest (not in a group lesson: one screen for the class);
 *   - a card that has not graduated (new / learning / relearning) → flash;
 *   - rung 0 (`repetitions` below `meaning_from_repetitions`) → flash, the learner's own grade;
 *   - rung 1 (`meaning_from_repetitions` ≤ `repetitions` < `choice_from_repetitions`) → meaning: the word is seen, its
 *     translation picked among four; needs `meaning_enabled` and the learner's setting; also in a group lesson;
 *   - rung 2 (`repetitions` ≥ `choice_from_repetitions`) → reverse / audio, alternating by the parity of
 *     (repetitions + word id); a type the learner switched off gives way to the other; a group lesson has no audio;
 *   - rung 3 (`interval_days` ≥ `typed_from_interval_days`) → gap-fill when the example carries the word, else spelling,
 *     alternating which is preferred by the same parity; needs the Pro feature (or `typed_recall_in_free`) and the
 *     setting; a group lesson has no typing.
 *   A rung with no exercise left (switched off, not in the plan, not in this session mode) is served ONE RUNG LOWER, down
 *   to flash: a Free learner's typed rung is the options, options both off is the meaning, meaning off is flash. So the
 *   exercise of a card is the highest one available at or below its rung — nothing jumps over a rung.
 *   A lapse of a `review` card leaves `meta.ladder_floor` = the rung below the one it failed at
 *   ({@see ReviewSubmissionService}); the card is served at max(maturity, floor) until its interval catches up.
 *
 * With the ladder off the previous rules apply: flash below two passes, the Pro typed exercise on every third pass,
 * else crc32("{repetitions}:{word_id}") over flash / reverse / audio — the meaning exercise takes no part in them.
 */
final class ExerciseSelector
{
    public const MIN_REPETITIONS = 2;

    public const SERVER_GRADED_FROM = 3;

    public const SERVER_GRADED_EVERY = 3;

    public function __construct(
        private readonly GapFillBuilder $gapFill,
        private readonly LadderPolicy $policy,
    ) {}

    /**
     * @param  string|null  $example  the example the card shows this time (docs/06 §12.4); null = `words.example`
     */
    public function select(
        WordRepetition $repetition,
        Word $word,
        Entitlement $entitlement,
        LearnerExerciseSettings $settings,
        string $sessionMode,
        ?string $example = null,
    ): ExerciseType {
        $repetitions = (int) $repetition->repetitions;
        $group = $sessionMode === TrainingSession::MODE_GROUP_LESSON;

        if ($this->policy->pretestEnabled && $repetition->state === RepetitionState::New && ! $group) {
            return ExerciseType::Pretest;
        }

        if ($repetition->state !== RepetitionState::Review) {
            return ExerciseType::Flash;
        }

        if (! $this->policy->ladderEnabled) {
            return $this->legacy($repetition, $word, $entitlement, $settings, $group, $example);
        }

        $parity = ($repetitions + (int) $word->id) % 2;

        // From the card's rung downwards: the first rung that has an exercise for this learner, plan and session mode.
        for ($rung = $this->policy->rungOf($repetition); $rung > LadderPolicy::RUNG_FLASH; $rung--) {
            $type = match ($rung) {
                LadderPolicy::RUNG_TYPED => $group ? null : $this->typed($word, $entitlement, $settings, $parity, $example),
                LadderPolicy::RUNG_CHOICE => $this->choice($settings, $group, $parity),
                default => $this->available(ExerciseType::Meaning, $entitlement, $settings) ? ExerciseType::Meaning : null,
            };
            if ($type !== null) {
                return $type;
            }
        }

        return ExerciseType::Flash;
    }

    /** The plan has the feature the type needs (if any), the type is on in the config and the learner has not switched it off. */
    public function available(ExerciseType $type, Entitlement $entitlement, LearnerExerciseSettings $settings): bool
    {
        if ($type === ExerciseType::Meaning && ! $this->policy->meaningEnabled) {
            return false;
        }

        $feature = $type->feature();
        $planAllows = $feature === null || $entitlement->can($feature) || ($type->rung() === LadderPolicy::RUNG_TYPED && $this->policy->typedRecallInFree);

        return $planAllows && $settings->allows($type);
    }

    /** Whether the plan (or `typed_recall_in_free`) gives the learner any typed exercise at all. */
    public function typedAvailableToPlan(Entitlement $entitlement): bool
    {
        return $this->policy->typedRecallInFree || $entitlement->can('exercise.spelling') || $entitlement->can('exercise.gap_fill');
    }

    private function typed(Word $word, Entitlement $entitlement, LearnerExerciseSettings $settings, int $parity, ?string $example): ?ExerciseType
    {
        $gapFill = $this->available(ExerciseType::GapFill, $entitlement, $settings)
            && $this->gapFill->fromText($word->word, $example ?? $word->example) !== null;
        $spelling = $this->available(ExerciseType::Spelling, $entitlement, $settings);

        if ($gapFill && $spelling) {
            return $parity === 0 ? ExerciseType::GapFill : ExerciseType::Spelling;
        }
        if ($gapFill) {
            return ExerciseType::GapFill;
        }

        return $spelling ? ExerciseType::Spelling : null;
    }

    /** The options rung: reverse / audio by parity, whichever is left when one is off; null when neither is available. */
    private function choice(LearnerExerciseSettings $settings, bool $group, int $parity): ?ExerciseType
    {
        $reverse = $settings->reverse;
        $audio = $settings->audio && ! $group;

        if ($reverse && $audio) {
            return $parity === 0 ? ExerciseType::Reverse : ExerciseType::Audio;
        }
        if ($reverse) {
            return ExerciseType::Reverse;
        }

        return $audio ? ExerciseType::Audio : null;
    }

    /** The rules before the ladder (docs/06 §4.3), kept behind `learning.ladder.enabled = false`. */
    private function legacy(WordRepetition $repetition, Word $word, Entitlement $entitlement, LearnerExerciseSettings $settings, bool $group, ?string $example): ExerciseType
    {
        $repetitions = (int) $repetition->repetitions;

        if ($repetitions < self::MIN_REPETITIONS) {
            return ExerciseType::Flash;
        }

        if ($group) {
            return $this->rotate([ExerciseType::Flash, ExerciseType::Reverse], $settings, $repetitions, (int) $word->id);
        }

        if ($repetitions >= self::SERVER_GRADED_FROM && $repetitions % self::SERVER_GRADED_EVERY === 0) {
            if ($this->available(ExerciseType::GapFill, $entitlement, $settings) && $this->gapFill->fromText($word->word, $example ?? $word->example) !== null) {
                return ExerciseType::GapFill;
            }
            if ($this->available(ExerciseType::Spelling, $entitlement, $settings)) {
                return ExerciseType::Spelling;
            }
        }

        return $this->rotate([ExerciseType::Flash, ExerciseType::Reverse, ExerciseType::Audio], $settings, $repetitions, (int) $word->id);
    }

    /**
     * @param  list<ExerciseType>  $candidates
     */
    private function rotate(array $candidates, LearnerExerciseSettings $settings, int $repetitions, int $wordId): ExerciseType
    {
        $allowed = array_values(array_filter($candidates, static fn (ExerciseType $t): bool => $settings->allows($t)));

        return $allowed[crc32($repetitions.':'.$wordId) % count($allowed)];
    }
}
