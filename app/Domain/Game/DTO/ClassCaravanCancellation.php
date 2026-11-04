<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

use App\Models\ClassCaravan;

/**
 * The outcome of `ClassCaravanService::cancel()`: the caravan as it is now, and whether THIS call canceled it. A caravan that was
 * already over (finished by the scheduler a moment ago) or canceled by a parallel call is returned with `canceledNow = false`, and
 * the chat is not told "canceled" a second time or after the summary.
 */
final readonly class ClassCaravanCancellation
{
    public function __construct(
        public ClassCaravan $caravan,
        public bool $canceledNow,
    ) {}
}
