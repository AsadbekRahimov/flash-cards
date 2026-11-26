<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Cup\Services\CupScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The clock of the friends' cups (docs/33 §3.2): expires drafts, starts / moves / cancels scheduled cups, builds tomorrow's snapshots,
 * closes abandoned attempts, finishes cups. Scheduled every five minutes `withoutOverlapping`; every step is idempotent, so a repeated
 * or a parallel run changes nothing twice. Runs whatever `CUP_ACCESS` says: a cup started during a pilot still has to finish.
 */
final class AdvanceCups extends Command
{
    /** @var string */
    protected $signature = 'cups:advance {--batch= : cups per step (default cup.advance_batch)}';

    /** @var string */
    protected $description = 'Move the friends\' cups along their clock (start, days, finish).';

    public function handle(CupScheduler $scheduler): int
    {
        $batch = $this->option('batch');
        $report = $scheduler->advance(CarbonImmutable::now(), is_numeric($batch) ? max(1, (int) $batch) : null);

        $this->info(collect($report)->map(static fn (int $n, string $k): string => "{$k}: {$n}")->implode(', '));

        return $report['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
