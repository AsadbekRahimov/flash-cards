<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Engagement\Services\MonthlyRatingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * `leagues:refresh-month` (docs/13 §7.6): recomputes `league_month_scores` of the open months (the current one, created
 * when missing, and the one that just ended while it waits for its close) from `learner_daily_stats` — by default only the
 * learners with a day row dated in the last `leagues.monthly.refresh_recent_days` (older rows never change), with
 * `--full` everybody. One statement per month, outside any review transaction; scheduled every 10 minutes.
 */
final class RefreshLeagueMonth extends Command
{
    protected $signature = 'leagues:refresh-month {--full : Recompute every learner of the month, not only the recently active}';

    protected $description = 'Refresh the materialised scores of the monthly rating from the day rows';

    public function handle(MonthlyRatingService $rating): int
    {
        $refreshed = $rating->refreshDue(CarbonImmutable::now(), (bool) $this->option('full'));

        $this->info("League months refreshed: {$refreshed}");

        return self::SUCCESS;
    }
}
