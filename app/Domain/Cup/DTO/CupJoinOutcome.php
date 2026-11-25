<?php

declare(strict_types=1);

namespace App\Domain\Cup\DTO;

use App\Models\CupParticipant;

/** What joining a cup did: the participant's row, and whether it is new (201) or already there (200). */
final readonly class CupJoinOutcome
{
    public function __construct(
        public CupParticipant $participant,
        public bool $created,
    ) {}
}
