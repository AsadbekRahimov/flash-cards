<?php

declare(strict_types=1);

namespace App\Listeners\Organizations;

use App\Domain\Organizations\Events\OrganizationMemberJoined;
use App\Domain\Organizations\Events\OrganizationMemberLeft;
use App\Domain\Organizations\Events\OrganizationMemberSeated;
use App\Domain\Organizations\Services\OrgOverviewService;

/**
 * A member joined, left or got a seat: the seats and the member counts of the `/org` dashboard moved, so the cached
 * overview of that center is dropped now instead of at the end of its TTL (docs/19 K2). Synchronous and one counter
 * increment: the events are raised after commit by OrganizationMembershipService. Auto-discovered (`app/Listeners`).
 */
final class ForgetOrgOverview
{
    public function handle(OrganizationMemberJoined|OrganizationMemberLeft|OrganizationMemberSeated $event): void
    {
        OrgOverviewService::forget($event->organizationId);
    }
}
