<?php

declare(strict_types=1);

namespace App\Domain\Learning\Enums;

/**
 * How a card is presented and who grades it (docs/13 §2.5, docs/06 §4.3).
 *
 *   flash     — word → reveal translation, the learner grades themself (0..5)
 *   reverse   — translation → 4 English words to choose from, graded by the client
 *   audio     — the word is only heard → 4 translations to choose from, graded by the client
 *   spelling  — translation → the learner types the word; graded by the SERVER (Pro)
 *   gap_fill  — the example with the word masked → the learner types the form; graded by the SERVER (Pro)
 */
enum ExerciseType: string
{
    case Flash = 'flash';
    case Reverse = 'reverse';
    case Audio = 'audio';
    case Spelling = 'spelling';
    case GapFill = 'gap_fill';

    /** The answer is text the server grades (`POST /review {answer}`); the client never sends `quality`. */
    public function serverGraded(): bool
    {
        return match ($this) {
            self::Spelling, self::GapFill => true,
            default => false,
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
