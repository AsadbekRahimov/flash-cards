<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Cup\Services\CupDuelService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The clock of the duels of the cups (docs/33 §3.2, §5.7): open challenges past their time → `expired` (one statement per batch, the
 * public ones get their edit), accepted duels whose battle is over but whose result no job has taken → scored. Every minute,
 * `withoutOverlapping`; idempotent, so a repeated or a parallel run changes nothing twice. Runs whatever `CUP_DUELS` says: a duel made
 * while the switch was on still has to end.
 */
final class SweepCupDuels extends Command
{
    /** @var string */
    protected $signature = 'cups:sweep-duels {--batch= : rows per step (default cup.duel.sweep_batch)}';

    /** @var string */
    protected $description = 'Expire the open duel challenges of the cups and score the duels whose battle is over.';

    public function handle(CupDuelService $duels): int
    {
        $batch = $this->option('batch');
        $report = $duels->sweep(CarbonImmutable::now(), is_numeric($batch) ? max(1, (int) $batch) : null);

        $this->info(collect($report)->map(static fn (int $n, string $k): string => "{$k}: {$n}")->implode(', '));

        return $report['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
