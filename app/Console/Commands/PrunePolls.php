<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Polls\Services\PollPruner;
use Illuminate\Console\Command;

/**
 * Deletes the votes and deliveries of polls closed or stopped longer ago than `polls.retention_days` (docs/35 O7, {@see PollPruner});
 * the polls and their totals stay. In chunks, each its own short transaction.
 *
 * Scheduled daily; a repeated or parallel run deletes nothing twice.
 */
final class PrunePolls extends Command
{
    /** @var string */
    protected $signature = 'polls:prune';

    /** @var string */
    protected $description = 'Delete the answers of polls in the bot kept longer than the retention period.';

    public function handle(PollPruner $pruner): int
    {
        $out = $pruner->prune();

        $this->info(sprintf('Polls pruned: %d (votes %d, deliveries %d)', $out['polls'], $out['votes'], $out['deliveries']));

        return self::SUCCESS;
    }
}
