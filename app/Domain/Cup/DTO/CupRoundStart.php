<?php

declare(strict_types=1);

namespace App\Domain\Cup\DTO;

/** The start of the round of the day: the attempt, and whether this call made it (201) or returned the one already open (200). */
final readonly class CupRoundStart
{
    public function __construct(
        public CupRoundView $view,
        public bool $created,
    ) {}
}
