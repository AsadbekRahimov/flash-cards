<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Contracts\SchedulerAlgorithm;
use App\Domain\Learning\Enums\SchedulerKind;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;

/**
 * The SM-2 branch of the A/B (docs/13 §2.5): a thin wrapper over SpacedRepetitionEngine::applyReview,
 * which keeps its contract and behaviour exactly (every existing test runs against the engine itself).
 */
final class Sm2Scheduler implements SchedulerAlgorithm
{
    public function __construct(private readonly SpacedRepetitionEngine $engine) {}

    public function key(): SchedulerKind
    {
        return SchedulerKind::Sm2;
    }

    public function schedule(WordRepetition $rep, int $quality, CarbonImmutable $now): void
    {
        $this->engine->applyReview($rep, $quality, $now);
    }
}
