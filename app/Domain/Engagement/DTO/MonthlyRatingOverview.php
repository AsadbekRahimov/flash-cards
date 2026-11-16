<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Models\LeagueMonth;

/** Everything `GET /api/twa/league/month` shows (docs/04 A.19), assembled by MonthlyRatingService::overview. */
final readonly class MonthlyRatingOverview
{
    /**
     * @param  LeagueMonth|null  $month  the row of the current month; null when the rating is off
     * @param  string  $startsOn  `Y-m-01` of the current month (league zone)
     * @param  string  $endsOn  its last day
     * @param  string  $closesAt  ISO 8601 UTC instant of the 1st of the next month 00:00 in the league zone
     * @param  int  $daysLeft  calendar days left including today (1 on the last day)
     * @param  array{daily_cap: int, min_active_days: int, min_account_days: int, places: int, top: int}  $rules
     * @param  list<MonthlyPrize>  $prizes  empty = no prize block
     * @param  list<MonthlyStanding>  $top
     */
    public function __construct(
        public bool $enabled,
        public ?LeagueMonth $month,
        public string $startsOn,
        public string $endsOn,
        public string $closesAt,
        public int $daysLeft,
        public int $participantsCount,
        public array $rules,
        public array $prizes,
        public array $top,
        public ?MonthlyMyLine $me,
        public bool $optedOut,
        public ?LastMonthView $lastMonth,
    ) {}
}
