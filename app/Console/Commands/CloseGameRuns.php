<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Game\Services\Runs\RunService;
use Illuminate\Console\Command;

/**
 * Closes the runs of the game past their `expires_at` (`finished` / `expired`, `active_learner_id` released, the event
 * `game.run_finished` once, nothing is paid), docs/18 §12.2.1, §12.5.1.
 *
 * Scheduled `everyFifteenMinutes()->withoutOverlapping()`; a repeated or parallel run is harmless (one conditional UPDATE per run,
 * claimed by whoever gets there first).
 */
final class CloseGameRuns extends Command
{
    /** @var string */
    protected $signature = 'game:close-expired';

    /** @var string */
    protected $description = 'Close game runs whose lifetime has passed.';

    public function handle(RunService $runs): int
    {
        $closed = $runs->closeExpired();

        $this->info("Game runs closed: {$closed}");

        return self::SUCCESS;
    }
}
