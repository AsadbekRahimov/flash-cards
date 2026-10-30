<?php

declare(strict_types=1);

namespace App\Domain\Practice\Enums;

/**
 * The four formats of the practice (docs/16 §0). A solo session and a battle each play exactly one of them.
 * The `value` is the key everywhere: `practice_sessions.mode`, `battles.mode`, `config('practice.modes.<value>')`,
 * the route payloads.
 */
enum PracticeMode: string
{
    case Pairs = 'pairs';
    case Collocation = 'collocation';
    case Sentence = 'sentence';
    case Dictation = 'dictation';

    /** The kind of an element of the snapshot: the mode itself, except for pairs (a round of pairs). */
    public function elementKind(): string
    {
        return match ($this) {
            self::Pairs => 'pairs_round',
            default => $this->value,
        };
    }

    /** The authored task type behind the mode; pairs have none - they take the `words` (docs/16 §2.2). */
    public function itemType(): ?PracticeItemType
    {
        return match ($this) {
            self::Pairs => null,
            self::Collocation => PracticeItemType::Collocation,
            self::Sentence => PracticeItemType::Sentence,
            self::Dictation => PracticeItemType::Dictation,
        };
    }

    /** The "≈ N min" of the hub card in seconds (docs/16 §7.1). */
    public function estimateSeconds(): int
    {
        return match ($this) {
            self::Pairs => 120,
            self::Collocation => 90,
            self::Sentence => 150,
            self::Dictation => 180,
        };
    }

    /** The mode switch `PRACTICE_MODE_*` (config/practice.php); it blocks only the creation of a session. */
    public function isEnabled(): bool
    {
        return (bool) config("practice.modes.{$this->value}", false);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }
}
