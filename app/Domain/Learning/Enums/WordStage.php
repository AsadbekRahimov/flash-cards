<?php

declare(strict_types=1);

namespace App\Domain\Learning\Enums;

use App\Models\WordRepetition;

/**
 * The stage of a word as the learner sees it (docs/06 §13.3, `config/learning.php` → `stages.*`), read from the row of
 * `word_repetitions`: met but not graduated → `new`; graduated with a short interval (or lapsed and relearning) →
 * `learning`; from `known_from_interval_days` → `known`; from `mastered_from_interval_days` → `mastered`.
 * The order of the cases is the order of the stages ({@see rank()}); the session summary counts the words that climbed.
 */
enum WordStage: string
{
    case New = 'new';
    case Learning = 'learning';
    case Known = 'known';
    case Mastered = 'mastered';

    public function rank(): int
    {
        return match ($this) {
            self::New => 0,
            self::Learning => 1,
            self::Known => 2,
            self::Mastered => 3,
        };
    }

    /** @return array{known: int, mastered: int} the interval thresholds (days), each at least 1 and in order */
    public static function thresholds(): array
    {
        $known = max(1, (int) config('learning.stages.known_from_interval_days', 7));
        $mastered = max($known, (int) config('learning.stages.mastered_from_interval_days', 21));

        return ['known' => $known, 'mastered' => $mastered];
    }

    /** The stage of a graduated-or-not card by its state and interval; `new` / `learning` states are the first stage. */
    public static function of(WordRepetition $repetition): self
    {
        if ($repetition->state === RepetitionState::New || $repetition->state === RepetitionState::Learning) {
            return self::New;
        }

        return self::ofInterval((int) $repetition->interval_days);
    }

    /** The stage a day-based interval earns (a relearning card has interval 0: `learning`). */
    public static function ofInterval(int $intervalDays): self
    {
        $t = self::thresholds();

        return match (true) {
            $intervalDays >= $t['mastered'] => self::Mastered,
            $intervalDays >= $t['known'] => self::Known,
            default => self::Learning,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
