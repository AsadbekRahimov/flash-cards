<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Engagement\Services\MonthlyRatingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * `leagues:close-month` (docs/13 §7.6): closes every month of the rating whose close is due — the 1st of the next month
 * `leagues.monthly.close_delay_minutes` after 00:00 in the league time zone — into `pending_review`: the authoritative
 * recompute of the scores, the final ranks, the candidates with their signals. Scheduled daily at the derived time and
 * five minutes later (the safety run; other days catch up a missed run). Idempotent: a closed month is skipped, a
 * `closing` one left by a crash is redone in one transaction.
 */
final class CloseLeagueMonth extends Command
{
    protected $signature = 'leagues:close-month';

    protected $description = 'Close the months of the rating whose close is due: final scores and ranks, the candidates for the review';

    public function handle(MonthlyRatingService $rating): int
    {
        $closed = $rating->closeDue(CarbonImmutable::now());

        $this->info("League months closed: {$closed}");

        return self::SUCCESS;
    }
}
