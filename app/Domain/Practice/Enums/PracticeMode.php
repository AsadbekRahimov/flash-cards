<?php

declare(strict_types=1);

namespace App\Domain\Practice\Enums;

/**
 * The formats of the practice (docs/16 §0). A solo session and a battle each play exactly one of them; `pairs_timed`, `word_form` and
 * `error_hunt` are solo only ({@see battleable()}). The order of the cases is the order of the cards of the hub.
 * The `value` is the key everywhere: `practice_sessions.mode`, `battles.mode`, `config('practice.modes.<value>')`,
 * the route payloads.
 */
enum PracticeMode: string
{
    case Pairs = 'pairs';
    case Collocation = 'collocation';
    case Sentence = 'sentence';
    case Dictation = 'dictation';
    /** "Find the pairs" against the clock (docs/16 §5.5a): the clock and the score are the server's. */
    case PairsTimed = 'pairs_timed';
    /** "Word form" (docs/16 §5.6): the learner types the form of a base word that a sentence needs. */
    case WordForm = 'word_form';
    /** "Find the mistake" (docs/16 §5.7): two steps per element - find the wrong word, then pick its correction. */
    case ErrorHunt = 'error_hunt';

    /** The kind of an element of the snapshot: the mode itself, except for pairs (a round of pairs). */
    public function elementKind(): string
    {
        return match ($this) {
            self::Pairs, self::PairsTimed => 'pairs_round',
            default => $this->value,
        };
    }

    /** The authored task type behind the mode; pairs have none - they take the `words` (docs/16 §2.2). */
    public function itemType(): ?PracticeItemType
    {
        return match ($this) {
            self::Pairs, self::PairsTimed => null,
            self::Collocation => PracticeItemType::Collocation,
            self::Sentence => PracticeItemType::Sentence,
            self::Dictation => PracticeItemType::Dictation,
            self::WordForm => PracticeItemType::WordForm,
            self::ErrorHunt => PracticeItemType::ErrorHunt,
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
            self::PairsTimed => 60,
            self::WordForm => 120,
            self::ErrorHunt => 150,
        };
    }

    /** Whether a battle may play the mode (the invitation and the options of a battle leave the others out). */
    public function battleable(): bool
    {
        return ! in_array($this, [self::PairsTimed, self::WordForm, self::ErrorHunt], true);
    }

    /**
     * A mode whose element takes more than one answer (docs/16 §6.3): "find the mistake" - the wrong word, then its correction. Its
     * answers carry `element_done`, and an answer to the other step is 409 `step_not_current`.
     */
    public function isMultiStep(): bool
    {
        return $this === self::ErrorHunt;
    }

    /** A mode with a clock: `deadline_at`, `score`, `POST …/start`. */
    public function isTimed(): bool
    {
        return $this === self::PairsTimed;
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
