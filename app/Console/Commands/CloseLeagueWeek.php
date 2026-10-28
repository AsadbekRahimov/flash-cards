<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Engagement\Services\LeagueService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * `leagues:close-week` (docs/13 §2.4, §7.3): closes every league week that ended before the
 * current one — final points, ranks, promotions / demotions group by group, then the result DMs
 * in batches. Scheduled daily at 00:00 and 00:05 in the league time zone (only Monday has
 * something to close; the 00:05 run and the other days catch up after a failure or an outage).
 * Idempotent and resumable: a closed week is skipped, a finalised group is never written twice.
 */
final class CloseLeagueWeek extends Command
{
    protected $signature = 'leagues:close-week';

    protected $description = 'Close the league weeks that have ended: final standings, promotions, result messages';

    public function handle(LeagueService $leagues): int
    {
        $closed = $leagues->closeDue(CarbonImmutable::now());

        $this->info("League weeks closed: {$closed}");

        return self::SUCCESS;
    }
}
