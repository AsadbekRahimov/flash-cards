<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

/**
 * The light answer of `GET /api/twa/league/month/me` (docs/04 A.19) for the finish screen of a session: the learner's own
 * line of the current month, refreshed from their day rows first, and how many days are left.
 */
final readonly class MonthlyMeView
{
    public function __construct(
        public bool $enabled,
        public string $startsOn,
        public string $endsOn,
        public int $daysLeft,
        public ?MonthlyMyLine $me,
    ) {}
}
