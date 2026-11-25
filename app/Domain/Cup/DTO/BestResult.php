<?php

declare(strict_types=1);

namespace App\Domain\Cup\DTO;

/**
 * The result of a participant as the table counts it: the sum of the counted days, its round part (the first tie-break) and which days
 * counted (ascending).
 */
final readonly class BestResult
{
    /** @param list<int> $countedDays */
    public function __construct(
        public int $total,
        public int $roundPoints,
        public array $countedDays,
    ) {}
}
