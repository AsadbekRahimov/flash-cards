<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Polls\Services\PollSweeper;
use Illuminate\Console\Command;

/**
 * Brings what a crash left of a poll broadcast back on track (docs/35 O3, {@see PollSweeper}): stuck `sending` rows → `unknown`,
 * an unfinished insert of recipients resumed, dead chains restarted, a poll past its deadline or stopped cleaned up, `sending → sent`.
 *
 * Scheduled `everyFiveMinutes()->withoutOverlapping()`; a repeated or parallel run changes nothing twice.
 */
final class SweepPolls extends Command
{
    /** @var string */
    protected $signature = 'polls:sweep';

    /** @var string */
    protected $description = 'Repair poll broadcasts after crashes: stuck rows, unfinished inserts, dead job chains, finished polls.';

    public function handle(PollSweeper $sweeper): int
    {
        $out = $sweeper->sweep();

        $this->info(sprintf(
            'Polls swept: unknown %d, resumed %d, expired %d, restarted %d, skipped %d, finished %d',
            $out['unknown'], $out['resumed'], $out['expired'], $out['restarted'], $out['skipped'], $out['finished'],
        ));

        return self::SUCCESS;
    }
}
