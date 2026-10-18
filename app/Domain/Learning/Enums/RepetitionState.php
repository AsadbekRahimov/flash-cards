<?php

declare(strict_types=1);

namespace App\Domain\Learning\Enums;

/**
 * Lifecycle of a (learner, word) repetition.
 *
 *   new ──pass steps──▶ review ──fail──▶ relearning ──pass steps──▶ review
 *    │                    ▲                   │
 *    └─fail─▶ learning ───┘◀──────────────────┘ (fail: back to step 0)
 *
 * `new` and `learning` cards use minute-based learning steps; `review`
 * cards use day-based SM-2 intervals; `relearning` is the minute-based
 * recovery after a lapse of a graduated card.
 */
enum RepetitionState: string
{
    case New = 'new';
    case Learning = 'learning';
    case Review = 'review';
    case Relearning = 'relearning';

    /** Card is scheduled in minutes and must be finished inside the session. */
    public function isIntraSession(): bool
    {
        return $this !== self::Review;
    }

    /**
     * @return list<string>
     */
    public static function intraSessionValues(): array
    {
        return [self::New->value, self::Learning->value, self::Relearning->value];
    }
}
