<?php

declare(strict_types=1);

namespace App\Domain\Learning\Support;

use App\Models\WordRepetition;

/**
 * The method settings of `config/learning.php` (docs/25 §3, docs/06 §12) in one immutable object: the exercise ladder,
 * objective grading, the leech threshold, hooks, example rotation, confusable words and the pretest. Built from the config
 * at resolution time (not a singleton), so a `config([...])` in a test applies to the next request.
 */
final readonly class LadderPolicy
{
    public const RUNG_FLASH = 0;

    public const RUNG_CHOICE = 1;

    public const RUNG_TYPED = 2;

    /** `word_repetitions.meta` key: the ceiling after a lapse (docs/06 §12.1), 0..2. */
    public const FLOOR_META = 'ladder_floor';

    /** `word_repetitions.meta` key: the exercise of the last accepted grade (the self-grade mismatch metric). */
    public const LAST_EXERCISE_META = 'last_exercise';

    /** `learners.meta` key of the FSRS retention mode. */
    public const RETENTION_MODE_META = 'retention_mode';

    public const RETENTION_MODES = ['calm', 'normal', 'intense'];

    public function __construct(
        public bool $ladderEnabled = true,
        public int $choiceFromRepetitions = 3,
        public int $typedFromIntervalDays = 21,
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
            choiceFromRepetitions: max(1, (int) $get('ladder.choice_from_repetitions', 3)),
            typedFromIntervalDays: max(1, (int) $get('ladder.typed_from_interval_days', 21)),
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

    /** The rung the card has earned by its maturity alone (docs/06 §12.1). */
    public function maturityRung(WordRepetition $repetition): int
    {
        if ((int) $repetition->interval_days >= $this->typedFromIntervalDays) {
            return self::RUNG_TYPED;
        }

        return (int) $repetition->repetitions >= $this->choiceFromRepetitions ? self::RUNG_CHOICE : self::RUNG_FLASH;
    }

    /** The ceiling a lapse left on the card (`meta.ladder_floor`), 0 when none. */
    public function floorRung(WordRepetition $repetition): int
    {
        $meta = $repetition->meta;
        $floor = is_array($meta) ? ($meta[self::FLOOR_META] ?? 0) : 0;

        return is_numeric($floor) ? max(self::RUNG_FLASH, min(self::RUNG_TYPED, (int) $floor)) : self::RUNG_FLASH;
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
