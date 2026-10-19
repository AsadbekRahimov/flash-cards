<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Events;

/**
 * A waitlisted member got a seat (FIFO after a leave or a tariff upgrade), docs/13 §1.3.
 * Raised after commit, once per promoted member; the bot (2B.2) may tell them "Pro is on".
 */
final readonly class OrganizationMemberSeated
{
    public function __construct(
        public int $organizationId,
        public int $learnerId,
        public int $memberId,
    ) {}
}
