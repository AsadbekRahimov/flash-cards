<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Events;

/**
 * A learner became an active member of an organization (docs/13 §2.3, §6.2). Raised after
 * commit by OrganizationMembershipService; `seatState` is `seated` or `waitlisted`. The bot
 * (2B.2) answers "You are in center X, Pro is on" / "no seats left yet" from it.
 */
final readonly class OrganizationMemberJoined
{
    public function __construct(
        public int $organizationId,
        public int $learnerId,
        public int $memberId,
        public string $via,
        public string $seatState,
    ) {}
}
