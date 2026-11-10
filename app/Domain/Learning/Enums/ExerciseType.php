<?php

declare(strict_types=1);

namespace App\Domain\Learning\Enums;

use App\Domain\Learning\Support\LadderPolicy;

/**
 * How a card is presented and who grades it (docs/13 §2.5, docs/06 §4.3, §12).
 *
 *   flash     — word → reveal translation, the learner grades themself (0..5)
 *   meaning   — the word (seen and heard) → 4 translations to choose from; graded by the SERVER from the picked option (`answer`)
 *   reverse   — translation → 4 English words to choose from; graded by the SERVER from the picked option
 *   audio     — the word is only heard → 4 translations to choose from; graded by the SERVER from the picked option
 *   spelling  — translation → the learner types the word; graded by the SERVER (Pro)
 *   gap_fill  — the example with the word masked → the learner types the form; graded by the SERVER (Pro)
 *   pretest   — a NEW word → 4 translations: "do you know it?"; right → graduates as "knew it", wrong → learning steps
 */
enum ExerciseType: string
{
    case Flash = 'flash';
    case Meaning = 'meaning';
    case Reverse = 'reverse';
    case Audio = 'audio';
    case Spelling = 'spelling';
    case GapFill = 'gap_fill';
    case Pretest = 'pretest';

    /**
     * The answer is text the server grades (`POST /review {answer}`); `quality` is refused. Reverse / audio are graded by
     * the server too, but a legacy `quality` may still be accepted for them (docs/06 §12.2); `meaning` came after that
     * transition and never takes a `quality`.
     */
    public function serverGraded(): bool
    {
        return match ($this) {
            self::Meaning, self::Spelling, self::GapFill, self::Pretest => true,
            default => false,
        };
    }

    /** The learner types the word: the card must not carry the word, its example, transcription or audio. */
    public function hidesWord(): bool
    {
        return match ($this) {
            self::Spelling, self::GapFill => true,
            default => false,
        };
    }

    /** The learner picks the meaning of a word they see: the card must not carry the translation or the example. */
    public function hidesTranslation(): bool
    {
        return $this === self::Pretest;
    }

    /**
     * The card is sent without what gives the right option away (docs/06 §12.2): reverse — the word (the right option), its
     * example, transcription, audio and hooks; meaning / audio — the translation, the example and the hooks. They come
     * back with the verdict of `POST /review` (`revealed`).
     */
    public function hidesChoiceAnswer(): bool
    {
        return match ($this) {
            self::Meaning, self::Reverse, self::Audio => true,
            default => false,
        };
    }

    /** Four options, one right: the pick is the answer (meaning, reverse, audio, pretest). */
    public function isChoice(): bool
    {
        return match ($this) {
            self::Meaning, self::Reverse, self::Audio, self::Pretest => true,
            default => false,
        };
    }

    /** The only exercise whose grade is the learner's own opinion. */
    public function selfGraded(): bool
    {
        return $this === self::Flash;
    }

    /** The rung of the ladder the exercise belongs to (docs/06 §12.1). */
    public function rung(): int
    {
        return match ($this) {
            self::Flash, self::Pretest => LadderPolicy::RUNG_FLASH,
            self::Meaning => LadderPolicy::RUNG_MEANING,
            self::Reverse, self::Audio => LadderPolicy::RUNG_CHOICE,
            self::Spelling, self::GapFill => LadderPolicy::RUNG_TYPED,
        };
    }

    /** The plan feature (config/billing.php `plans.*.features`) the exercise needs; null = any plan. */
    public function feature(): ?string
    {
        return match ($this) {
            self::Spelling => 'exercise.spelling',
            self::GapFill => 'exercise.gap_fill',
            default => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
