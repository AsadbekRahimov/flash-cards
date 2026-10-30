<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

use App\Models\Battle;

/**
 * A player reported a clip that does not play (docs/16 §10.3): `verified` - the server looked and the file is indeed
 * missing (the match is closed / voided); false - the file is there, the trouble is local, the match goes on.
 */
final readonly class AudioFailureOutcome
{
    public function __construct(
        public bool $verified,
        public Battle $battle,
    ) {}
}
