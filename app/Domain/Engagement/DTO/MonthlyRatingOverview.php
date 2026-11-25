<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Enums\ScoreCategory;
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
     * @param  list<MonthlyStanding>  $top  of a v2 month: the top of the table shown ({@see $category})
     * @param  ScoreCategory|null  $category  a v2 month: the table shown - the one asked for, else the viewer's ({@see $myCategory}); null in v1
     * @param  ScoreCategory|null  $myCategory  a v2 month: the viewer's table - the category of their row, else of their plan
     * @param  array{above: list<BoardRow>, below: list<BoardRow>, rank: int, next_gap: int|null}|null  $neighbours  a v2 month, the
     *                                                                                                               viewer's own table
     *                                                                                                               and a participant
     * @param  BoardCursor|null  $nextCursor  a v2 month: where the page after the top continues; null - the top is the whole table
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
        public int $rulesVersion = 1,
        public ?ScoreCategory $category = null,
        public ?ScoreCategory $myCategory = null,
        public ?array $neighbours = null,
        public ?BoardCursor $nextCursor = null,
    ) {}
}
