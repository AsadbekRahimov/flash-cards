<?php

declare(strict_types=1);

namespace App\Domain\Cup\DTO;

use App\Models\CupDuel;

/** A challenge or an acceptance of a duel: the duel as it is now, and whether this call made it (201) or found it made (200). */
final readonly class CupDuelOutcome
{
    public function __construct(
        public CupDuel $duel,
        public bool $created,
    ) {}
}
