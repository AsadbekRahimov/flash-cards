<?php

declare(strict_types=1);

namespace App\Domain\Cup\DTO;

use App\Models\Cup;

/** What "create a cup" did (docs/33 §3.1): the open cup of the chat, and whether it is this call's new draft. */
final readonly class CupProposal
{
    public function __construct(
        public Cup $cup,
        public bool $created,
    ) {}
}
