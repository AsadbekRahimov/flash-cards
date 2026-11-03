<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Learners\Services\LearnerPurgeService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Final deletion of learners soft-deleted more than `learners.purge.after_days` days ago (docs/03 §8 rule 2,
 * docs/07 §10). Scheduled daily; idempotent — a repeated or parallel run deletes nothing twice (purge lock + a row
 * lock per learner). Payment records are kept whole.
 */
final class PurgeDeletedLearners extends Command
{
    /** @var string */
    protected $signature = 'learners:purge-deleted {--limit= : At most this many learners this run (default: learners.purge.max_per_run)}';

    /** @var string */
    protected $description = 'Delete for good the learners whose account deletion is older than the retention period; payment records stay.';

    public function handle(LearnerPurgeService $purge): int
    {
        $limit = $this->option('limit');
        $report = $purge->purgeDue(CarbonImmutable::now(), is_numeric($limit) ? max(1, (int) $limit) : null);

        if ($report->locked) {
            $this->warn('Another purge run holds the lock; nothing done.');

            return self::SUCCESS;
        }

        $this->info("Learners purged: {$report->purged}".($report->skipped > 0 ? " (skipped: {$report->skipped})" : ''));

        if ($report->capped) {
            $this->warn('The per-run limit was reached; the rest waits for the next run.');
        }

        return self::SUCCESS;
    }
}
