<?php

declare(strict_types=1);

namespace App\Domain\Learning\Support;

use App\Domain\Learning\Services\ExerciseSelector;
use App\Models\WordRepetition;

/**
 * The method settings of `config/learning.php` (docs/25 §3, docs/06 §12) in one immutable object: the exercise ladder,
 * objective grading, the leech threshold, hooks, example rotation, confusable words and the pretest. Built from the config
 * at resolution time (not a singleton), so a `config([...])` in a test applies to the next request.
 *
 * The rungs (docs/06 §12.1), bottom to top: flash → meaning → choice (reverse / audio) → typed. A `review` card is served
 * at max(the rung its maturity earned, the floor a lapse left); each rung has its thresholds below.
 */
final readonly class LadderPolicy
{
    /** Self-graded flash: cards in minute steps and the first passes after graduation. */
    public const RUNG_FLASH = 0;

    /** Recognition of the meaning: the word is seen, its translation picked among four (`meaning`). */
    public const RUNG_MEANING = 1;

    /** Recognition of the form: the word picked by its translation or by ear (`reverse` / `audio`). */
    public const RUNG_CHOICE = 2;

    /** Typed recall (`spelling` / `gap_fill`). */
    public const RUNG_TYPED = 3;

    public const RUNG_TOP = self::RUNG_TYPED;

    /**
     * `word_repetitions.meta` key: the ceiling after a lapse (docs/06 §12.1), 1..3 (0 is not stored). Numbered as the
     * rungs above since the `meaning` rung was added (migration 2026_11_12_100002 moved the stored 1 / 2 up by one).
     */
    public const FLOOR_META = 'ladder_floor';

    /** `word_repetitions.meta` key: the exercise of the last accepted grade (the self-grade mismatch metric). */
    public const LAST_EXERCISE_META = 'last_exercise';

    /** `learners.meta` key of the FSRS retention mode. */
    public const RETENTION_MODE_META = 'retention_mode';

    public const RETENTION_MODES = ['calm', 'normal', 'intense'];

    public function __construct(
        public bool $ladderEnabled = true,
        public int $meaningFromRepetitions = 2,
        public int $choiceFromRepetitions = 3,
        public int $typedFromIntervalDays = 21,
        public bool $meaningEnabled = true,
        public bool $typedRecallInFree = false,
        public bool $acceptLegacyChoiceQuality = true,
        public int $leechThreshold = 4,
        public bool $hooksEnabled = true,
        public int $noteMaxLength = 200,
        public bool $rotateExamples = true,
        public bool $confusablesEnabled = true,
        public int $confusablesWindowHours = 20,
        public int $confusablesLookahead = 5,
        public bool $pretestEnabled = false,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $c */
        $c = (array) config('learning', []);
        $get = static function (string $path, mixed $default) use ($c): mixed {
            $value = $c;
            foreach (explode('.', $path) as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    return $default;
                }
                $value = $value[$segment];
            }

            return $value;
        };

        return new self(
            ladderEnabled: (bool) $get('ladder.enabled', true),
            meaningFromRepetitions: max(1, (int) $get('ladder.meaning_from_repetitions', 2)),
            choiceFromRepetitions: max(1, (int) $get('ladder.choice_from_repetitions', 3)),
            typedFromIntervalDays: max(1, (int) $get('ladder.typed_from_interval_days', 21)),
            meaningEnabled: (bool) $get('ladder.meaning_enabled', true),
            typedRecallInFree: (bool) $get('ladder.typed_recall_in_free', false),
            acceptLegacyChoiceQuality: (bool) $get('objective_grading.accept_legacy_quality', true),
            leechThreshold: max(0, (int) $get('leech.threshold', 4)),
            hooksEnabled: (bool) $get('hooks.enabled', true),
            noteMaxLength: max(1, (int) $get('hooks.note_max_length', 200)),
            rotateExamples: (bool) $get('examples.rotate', true),
            confusablesEnabled: (bool) $get('confusables.enabled', true),
            confusablesWindowHours: max(1, (int) $get('confusables.window_hours', 20)),
            confusablesLookahead: max(1, (int) $get('confusables.lookahead', 5)),
            pretestEnabled: (bool) $get('pretest.enabled', false),
        );
    }

    /** A "hard word" (docs/25 §3.3): failed at least `leech.threshold` times as a graduated card. 0 = never. */
    public function isLeech(WordRepetition $repetition): bool
    {
        return $this->leechThreshold > 0 && (int) $repetition->lapses >= $this->leechThreshold;
    }

    /**
     * The rung the card has earned by its maturity alone (docs/06 §12.1). The `meaning` rung exists whether or not the
     * exercise is switched on (`meaning_enabled`, the learner's setting): a rung without an exercise is served one lower
     * ({@see ExerciseSelector}), and a floor left there keeps its meaning.
     */
    public function maturityRung(WordRepetition $repetition): int
    {
        if ((int) $repetition->interval_days >= $this->typedFromIntervalDays) {
            return self::RUNG_TYPED;
        }

        $repetitions = (int) $repetition->repetitions;
        if ($repetitions >= $this->choiceFromRepetitions) {
            return self::RUNG_CHOICE;
        }

        return $repetitions >= $this->meaningFromRepetitions ? self::RUNG_MEANING : self::RUNG_FLASH;
    }

    /** The ceiling a lapse left on the card (`meta.ladder_floor`), 0 when none. */
    public function floorRung(WordRepetition $repetition): int
    {
        $meta = $repetition->meta;
        $floor = is_array($meta) ? ($meta[self::FLOOR_META] ?? 0) : 0;

        return is_numeric($floor) ? max(self::RUNG_FLASH, min(self::RUNG_TOP, (int) $floor)) : self::RUNG_FLASH;
    }

    /** The rung a `review` card is served at: its maturity, but never below the step a lapse sent it back to. */
    public function rungOf(WordRepetition $repetition): int
    {
        return max($this->maturityRung($repetition), $this->floorRung($repetition));
    }

    /** The FSRS desired retention of a mode (`learning.fsrs.retention_modes`); null for an unknown mode. */
    public function retentionFor(?string $mode): ?float
    {
        if ($mode === null) {
            return null;
        }

        $modes = (array) config('learning.fsrs.retention_modes', []);
        $value = $modes[$mode] ?? null;

        return is_numeric($value) && (float) $value > 0.0 && (float) $value < 1.0 ? (float) $value : null;
    }
}
