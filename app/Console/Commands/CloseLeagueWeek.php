<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Engagement\Services\LeagueService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * `leagues:close-week` (docs/13 §2.4, §7.3): closes every league week that ended before the
 * current one — final points, ranks, promotions / demotions, result DMs. Scheduled daily at
 * 00:05 in the league time zone (only Monday has something to close; the other runs catch up
 * after an outage). Idempotent: a closed week is skipped.
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
