<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Cup\Services\CupOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The outbox of the friends' cups (docs/33 §6.4): every minute, rows left in `sending` for ten minutes (the worker died) become
 * `unknown` - never sent again - and `pending` rows nobody works on (the final the finish wrote, a job lost or given up) are queued
 * again. A job claims its row before it sends, so a row queued twice is still posted once.
 */
final class SendCupOutbox extends Command
{
    /** @var string */
    protected $signature = 'cups:send-outbox {--batch=200 : rows per run}';

    /** @var string */
    protected $description = 'Queue the pending messages of the friends\' cups and give up the stuck ones.';

    public function handle(CupOutbox $outbox): int
    {
        $batch = $this->option('batch');
        $report = $outbox->sweep(CarbonImmutable::now(), is_numeric($batch) ? max(1, (int) $batch) : 200);

        $this->info("stale: {$report['stale']}, queued: {$report['queued']}");

        return self::SUCCESS;
    }
}
