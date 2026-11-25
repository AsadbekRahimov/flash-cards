<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Cup\Services\CupDuelService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Takes the result of a duel of a cup once its battle is over (docs/33 §5.7): queued by the clock of the battle after the commit of its
 * end ({@see \App\Domain\Practice\Services\Battle\BattleClock}). Idempotent - {@see CupDuelService::score()} works under `FOR UPDATE` of the
 * duel and leaves a duel that is not `accepted` any more alone, so a double hook, a retry or `cups:sweep-duels` next to it scores once.
 */
final class ScoreCupDuelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly int $cupDuelId) {}

    public function handle(CupDuelService $duels): void
    {
        $duels->score($this->cupDuelId, CarbonImmutable::now());
    }
}
