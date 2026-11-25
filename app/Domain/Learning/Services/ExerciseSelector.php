<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Content\Support\Phrase;
use App\Domain\Learning\DTO\LearnerExerciseSettings;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Support\LadderPolicy;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;

/**
 * Picks the exercise a card is served with (docs/13 §2.5, docs/06 §4.3, §12.1, §13.1).
 *
 * Deterministic and stateless: the same (repetition row, word, plan, settings, session mode, example, audio) always gives
 * the same type, so `POST /review` recomputes it from the same inputs instead of remembering what `/next` served
 * (docs/13 §8.2). The inputs that change on an accepted review (`state`, `repetitions`, `interval_days`, `meta`) change
 * only after the grade is applied, and a card cannot be graded twice for one serve (docs/06 §5.2).
 *
 * The early ladder (docs/06 §13.1, `learning.ladder.early.*`, the default), for personal sessions:
 *   - a `new` card → flash: the first meeting teaches the word (the pretest, when on, replaces it);
 *   - a card in minute steps (learning / relearning) and a `review` card on rungs 0–1 → the RECOGNITION list
 *     (meaning, reverse, audio by default); a `review` card on rung 2 → the FORM list (reverse, audio, flash); the typed
 *     rung → gap-fill / spelling. On every list the first exercise that is available (the plan, the learner's settings,
 *     audio only with a file when `audio_requires_file`) AND differs from the exercise of the card's last grade
 *     (`meta.last_exercise`) wins, so no word gets the same format twice in a row and the formats alternate; a rung whose
 *     only candidate was just served borrows from the rung below; flash is the universal fallback.
 *   The choice cards are graded by the server (4 / 2) and move the step machine like a self-grade would.
 *
 * The ladder without its early part (`early.enabled = false`, docs/25 §3.1):
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
 *   Group lessons always follow these rules (one screen for the whole class, docs/06 §13.1).
 *
 * A phrase (docs/35 §3.3 b, docs/06 §16) is never served `spelling` - by no ladder, in no mode: its typed rung is the gap-fill of its
 * key word in the phrase itself (`GapFillBuilder::forCard`), and a phrase without a valid key word has no typed rung at all.
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
     * @param  bool  $hasAudio  the word has a pre-generated audio file (`card.audio_url`), docs/06 §13.1
     */
    public function select(
        WordRepetition $repetition,
        Word $word,
        Entitlement $entitlement,
        LearnerExerciseSettings $settings,
        string $sessionMode,
        ?string $example = null,
        bool $hasAudio = false,
    ): ExerciseType {
        $repetitions = (int) $repetition->repetitions;
        $group = $sessionMode === TrainingSession::MODE_GROUP_LESSON;

        if ($this->policy->pretestEnabled && $repetition->state === RepetitionState::New && ! $group) {
            return ExerciseType::Pretest;
        }

        if ($sessionMode === TrainingSession::MODE_HARD_REVIEW) {
            return $this->hardRound($repetition, $word, $entitlement, $settings, $example, $hasAudio);
        }

        $early = $this->policy->earlyLadder() && ! $group;

        if ($repetition->state !== RepetitionState::Review) {
            if (! $early || $repetition->state === RepetitionState::New) {
                return ExerciseType::Flash;
            }

            // A card in minute steps: the recognition list, never the same format as its last grade.
            return $this->alternate([$this->policy->earlyRecognition], $repetition, $word, $entitlement, $settings, $example, $hasAudio);
        }

        if (! $this->policy->ladderEnabled) {
            return $this->legacy($repetition, $word, $entitlement, $settings, $group, $example);
        }

        if ($early) {
            return $this->earlyReview($repetition, $word, $entitlement, $settings, $example, $hasAudio);
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

    /**
     * The round of hard words (`hard_review`, docs/06 §14): the cards of its set are `review` or `relearning`, and the round is
     * objective-only - never a self-graded flash. The lists are those of the ladder in use (the early lists, or the rungs of the
     * classic ladder: typed -> options -> meaning), highest rung first, with flash struck out of each; a card in minute steps takes the
     * recognition list. When no list has a servable exercise: the meaning, if available, else the reverse, else flash (a round is not offered to such a learner). A pure function of the
     * same inputs as every other mode, so `/review` recomputes the type of the serve.
     */
    private function hardRound(WordRepetition $repetition, Word $word, Entitlement $entitlement, LearnerExerciseSettings $settings, ?string $example, bool $hasAudio): ExerciseType
    {
        $strip = static fn (array $list): array => array_values(array_filter($list, static fn (ExerciseType $t): bool => $t !== ExerciseType::Flash));

        $lists = [];
        if ($repetition->state === RepetitionState::Review) {
            for ($rung = $this->policy->rungOf($repetition); $rung > LadderPolicy::RUNG_FLASH; $rung--) {
                $lists[] = match ($rung) {
                    LadderPolicy::RUNG_TYPED => [ExerciseType::GapFill, ExerciseType::Spelling],
                    LadderPolicy::RUNG_CHOICE => $this->policy->earlyLadder() ? $strip($this->policy->earlyForm) : [ExerciseType::Reverse, ExerciseType::Audio],
                    default => $strip($this->policy->earlyRecognition),
                };
            }
        }
        $lists[] = $strip($this->policy->earlyRecognition);

        $type = $this->alternate($lists, $repetition, $word, $entitlement, $settings, $example, $hasAudio);
        if ($type !== ExerciseType::Flash) {
            return $type;
        }

        // Nothing servable on the lists (the learner's settings, a deck without audio, a typed rung with no form): the plain pick.
        foreach ([ExerciseType::Meaning, ExerciseType::Reverse] as $fallback) {
            if ($this->available($fallback, $entitlement, $settings)) {
                return $fallback;
            }
        }

        // Not even those (the config and the settings switched both off - a round is not offered then): flash, the caller's "nothing servable",
        // never an exercise the config has switched off.
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

    /**
     * A `review` card under the early ladder: the lists of its rung and of the rungs below, in order (docs/06 §13.1) —
     * typed → form → recognition — the first exercise that is available and differs from the last grade's.
     */
    private function earlyReview(WordRepetition $repetition, Word $word, Entitlement $entitlement, LearnerExerciseSettings $settings, ?string $example, bool $hasAudio): ExerciseType
    {
        $lists = [];
        for ($rung = $this->policy->rungOf($repetition); $rung > LadderPolicy::RUNG_FLASH; $rung--) {
            $lists[] = match ($rung) {
                LadderPolicy::RUNG_TYPED => [ExerciseType::GapFill, ExerciseType::Spelling],
                LadderPolicy::RUNG_CHOICE => $this->policy->earlyForm,
                default => $this->policy->earlyRecognition,
            };
        }
        $lists[] = $this->policy->earlyRecognition;

        return $this->alternate($lists, $repetition, $word, $entitlement, $settings, $example, $hasAudio);
    }

    /**
     * The lists are cycles (highest rung first): the exercise AFTER the card's last grade's in the list, wrapping round —
     * so every format of the list gets its turn and none comes twice in a row; a last exercise that is not in the list
     * starts it from the top. Unavailable exercises are skipped; a list whose only available exercise was just served is
     * skipped for the next one; nothing left — flash (or, when flash was the last and some list had a candidate, that
     * candidate: a repeat beats nothing). The lists of the round of hard words carry no flash, so
     * a flash result is "nothing servable" to its caller.
     *
     * @param  list<list<ExerciseType>>  $lists
     */
    private function alternate(array $lists, WordRepetition $repetition, Word $word, Entitlement $entitlement, LearnerExerciseSettings $settings, ?string $example, bool $hasAudio): ExerciseType
    {
        $meta = $repetition->meta;
        $last = is_array($meta) ? ($meta[LadderPolicy::LAST_EXERCISE_META] ?? null) : null;
        $last = is_string($last) ? $last : null;
        $repeat = null;

        foreach ($lists as $list) {
            $count = count($list);
            $position = null;
            foreach ($list as $i => $type) {
                if ($type->value === $last) {
                    $position = $i;
                    break;
                }
            }
            $start = $position === null ? 0 : $position + 1;

            for ($i = 0; $i < $count; $i++) {
                $type = $list[($start + $i) % $count];
                if (! $this->servable($type, $word, $entitlement, $settings, $example, $hasAudio)) {
                    continue;
                }
                if ($type->value !== $last) {
                    return $type;
                }
                $repeat ??= $type;
            }
        }

        if ($last !== ExerciseType::Flash->value || $repeat === null) {
            return ExerciseType::Flash;
        }

        return $repeat;
    }

    /**
     * Available to the learner and possible for this serve: audio needs a file (when required), gap-fill the word in the example (a
     * phrase: its key word in itself), spelling a card that is not a phrase.
     */
    private function servable(ExerciseType $type, Word $word, Entitlement $entitlement, LearnerExerciseSettings $settings, ?string $example, bool $hasAudio): bool
    {
        if (! $this->available($type, $entitlement, $settings)) {
            return false;
        }

        return match ($type) {
            ExerciseType::Audio => $hasAudio || ! $this->policy->earlyAudioRequiresFile,
            ExerciseType::GapFill => $this->gapFill->forCard($word, $example) !== null,
            ExerciseType::Spelling => ! Phrase::isPhraseWord($word),
            ExerciseType::Pretest => false,
            default => true,
        };
    }

    private function typed(Word $word, Entitlement $entitlement, LearnerExerciseSettings $settings, int $parity, ?string $example): ?ExerciseType
    {
        $gapFill = $this->available(ExerciseType::GapFill, $entitlement, $settings)
            && $this->gapFill->forCard($word, $example) !== null;
        $spelling = ! Phrase::isPhraseWord($word) && $this->available(ExerciseType::Spelling, $entitlement, $settings);

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
            if ($this->available(ExerciseType::GapFill, $entitlement, $settings) && $this->gapFill->forCard($word, $example) !== null) {
                return ExerciseType::GapFill;
            }
            if (! Phrase::isPhraseWord($word) && $this->available(ExerciseType::Spelling, $entitlement, $settings)) {
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
