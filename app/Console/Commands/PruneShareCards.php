<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Engagement\Services\ShareCardPruner;
use Illuminate\Console\Command;

/**
 * Removes the share cards nobody needed for a while and the PNG cards of template 1 (docs/36 §2б). Weekly by the schedule; the
 * work is {@see ShareCardPruner}. A removed card is drawn again when it is asked for. Non-zero exit when a file could not be removed.
 */
final class PruneShareCards extends Command
{
    /** @var string */
    protected $signature = 'achievements:prune-cards
        {--days= : Remove a card unused for this many days (default: achievements.prune_after_days)}';

    /** @var string */
    protected $description = 'Delete the old share cards (unused for a month, old template, leftovers of a killed render).';

    public function handle(ShareCardPruner $pruner): int
    {
        $option = $this->option('days');
        $days = $option === null || $option === '' ? (int) config('achievements.prune_after_days', 30) : filter_var($option, FILTER_VALIDATE_INT);
        if ($days === false || $days < 1) {
            $this->error('--days must be a whole number of days, at least 1.');

            return self::INVALID;
        }

        $report = $pruner->prune($days);

        $this->line(sprintf('Share cards: deleted %d, kept %d, failed %d (unused for more than %d days).', $report['deleted'], $report['kept'], $report['failed'], $days));

        return $report['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
