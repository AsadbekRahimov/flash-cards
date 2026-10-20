<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Learning\DTO\LearnerExerciseSettings;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Enums\RepetitionState;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;

/**
 * Picks the exercise a card is served with (docs/13 §2.5, docs/06 §4.3).
 *
 * Deterministic and stateless: the same (repetition state, repetitions, word, plan, settings,
 * session mode) always gives the same type, so `POST /review` recomputes it from the same inputs
 * instead of remembering what `/next` served (docs/13 §8.2). The inputs that change on an
 * accepted review (`state`, `repetitions`) change only after the grade is applied, and a card
 * cannot be graded twice for one serve (docs/06 §5.2).
 *
 *   - a card that has not graduated (new / learning / relearning) or has fewer than
 *     {@see MIN_REPETITIONS} passes → flash;
 *   - `group_lesson` → flash or reverse only (one screen for the whole class, no audio in a classroom);
 *   - every third pass from {@see SERVER_GRADED_FROM} on, with the Pro feature and the setting on →
 *     gap_fill when the example contains the word, else spelling (or on to the next rule);
 *   - otherwise one of flash / reverse / audio (the ones switched on), chosen by
 *     crc32("{repetitions}:{word_id}") — stable per serve, different from pass to pass.
 */
final class ExerciseSelector
{
    public const MIN_REPETITIONS = 2;

    public const SERVER_GRADED_FROM = 3;

    public const SERVER_GRADED_EVERY = 3;

    public function __construct(private readonly GapFillBuilder $gapFill) {}

    public function select(
        WordRepetition $repetition,
        Word $word,
        Entitlement $entitlement,
        LearnerExerciseSettings $settings,
        string $sessionMode,
    ): ExerciseType {
        $repetitions = (int) $repetition->repetitions;

        if ($repetition->state !== RepetitionState::Review || $repetitions < self::MIN_REPETITIONS) {
            return ExerciseType::Flash;
        }

        if ($sessionMode === TrainingSession::MODE_GROUP_LESSON) {
            return $this->rotate([ExerciseType::Flash, ExerciseType::Reverse], $settings, $repetitions, (int) $word->id);
        }

        if ($repetitions >= self::SERVER_GRADED_FROM && $repetitions % self::SERVER_GRADED_EVERY === 0) {
            if ($this->available(ExerciseType::GapFill, $entitlement, $settings) && $this->gapFill->build($word) !== null) {
                return ExerciseType::GapFill;
            }
            if ($this->available(ExerciseType::Spelling, $entitlement, $settings)) {
                return ExerciseType::Spelling;
            }
        }

        return $this->rotate([ExerciseType::Flash, ExerciseType::Reverse, ExerciseType::Audio], $settings, $repetitions, (int) $word->id);
    }

    /** The plan has the feature the type needs (if any) and the learner has not switched it off. */
    public function available(ExerciseType $type, Entitlement $entitlement, LearnerExerciseSettings $settings): bool
    {
        $feature = $type->feature();

        return ($feature === null || $entitlement->can($feature)) && $settings->allows($type);
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
