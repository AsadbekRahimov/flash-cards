<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Practice\Services\Battle\BattleJanitor;
use Illuminate\Console\Command;

/**
 * Moves the battles by time (docs/16 §8.6): expired invitations are closed, matches that are on are advanced (absence,
 * deadlines, the end) one row lock at a time, and with `PRACTICE_ACCESS=off` the live matches are voided.
 *
 * Scheduled `everyMinute()->withoutOverlapping()->runInBackground()`; a repeated or parallel run finds nothing to do.
 */
final class SweepBattles extends Command
{
    /** @var string */
    protected $signature = 'practice:battles-sweep';

    /** @var string */
    protected $description = 'Expire battle invitations and move the battles that are on by the clock.';

    public function handle(BattleJanitor $janitor): int
    {
        $changed = $janitor->sweep();

        $this->info("Battles swept: {$changed}");

        return self::SUCCESS;
    }
}
