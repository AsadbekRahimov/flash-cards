<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Polls\Services\PollCloser;
use Illuminate\Console\Command;

/**
 * Closes the polls whose deadline has come (docs/35 O7, {@see PollCloser}): status `closed`, totals in `results_snapshot`, audit.
 *
 * Scheduled `everyFiveMinutes()->withoutOverlapping()`; a repeated or parallel run closes nothing twice.
 */
final class ClosePollsDue extends Command
{
    /** @var string */
    protected $signature = 'polls:close-due';

    /** @var string */
    protected $description = 'Close the polls in the bot whose deadline has come.';

    public function handle(PollCloser $closer): int
    {
        $this->info('Polls closed: '.$closer->closeDue());

        return self::SUCCESS;
    }
}
