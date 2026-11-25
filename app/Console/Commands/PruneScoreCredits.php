<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Engagement\Services\Scoring\ScoreCreditPruner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * `leagues:prune-score-credits` (docs/32 §2.7, task S16): deletes the rows of the journal `score_credits` older than
 * `leagues.scoring.credits_retention_days`, only days of closed months, in batches (no long transaction). Scheduled nightly
 * (routes/console.php); idempotent, safe to run by hand.
 */
final class PruneScoreCredits extends Command
{
    /** @var string */
    protected $signature = 'leagues:prune-score-credits
        {--batch=5000 : Rows per batch}';

    /** @var string */
    protected $description = 'Delete the word-points journal rows older than the retention (only days of closed months).';

    public function handle(ScoreCreditPruner $pruner): int
    {
        $batch = (int) $this->option('batch');
        if ($batch < 1) {
            $this->error('--batch must be a positive number.');

            return self::INVALID;
        }

        $now = CarbonImmutable::now();
        $cutoff = $pruner->cutoff($now)->toDateString();
        $deleted = $pruner->prune($now, $batch, fn (int $deleted) => $this->output->isVerbose() ? $this->line("  deleted: {$deleted}") : null);

        $this->info("Score credits before {$cutoff} deleted: {$deleted}");

        return self::SUCCESS;
    }
}
