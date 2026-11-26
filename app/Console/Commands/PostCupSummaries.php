<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Cup\Services\CupSummaries;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The summaries of the days of the friends' cups (docs/33 §3.2, §6.1): every five minutes, the running cups whose `summary_minute` has
 * come today and whose day is not decided yet get a summary in the outbox - only with new results - or the mark "decided, nothing new".
 * Idempotent (a unique row per day and a conditional UPDATE of the day), scheduled `withoutOverlapping`.
 */
final class PostCupSummaries extends Command
{
    /** @var string */
    protected $signature = 'cups:post-summaries {--batch=500 : cups per run}';

    /** @var string */
    protected $description = 'Queue the daily summaries of the friends\' cups that have new results.';

    public function handle(CupSummaries $summaries): int
    {
        $batch = $this->option('batch');
        $report = $summaries->enqueueDue(CarbonImmutable::now(), is_numeric($batch) ? max(1, (int) $batch) : 500);

        $this->info(collect($report)->map(static fn (int $n, string $k): string => "{$k}: {$n}")->implode(', '));

        return self::SUCCESS;
    }
}
