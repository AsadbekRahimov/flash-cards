<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Billing\Services\EntitlementResolver;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Only an `active` organization sponsors its groups (docs/12 §5.1): suspending
 * or deleting one drops the cached entitlement of every member of its groups.
 * Registered in AppServiceProvider.
 */
final class OrganizationObserver
{
    public function __construct(private readonly EntitlementResolver $entitlements) {}

    public function updated(Organization $organization): void
    {
        if ($organization->wasChanged('status')) {
            $organizationId = $organization->id;

            DB::afterCommit(fn () => $this->entitlements->forgetOrganization($organizationId));
        }
    }

    /** `telegram_groups.organization_id` is nulled by the FK: enumerate members now, forget after commit. */
    public function deleting(Organization $organization): void
    {
        /** @var list<int> $learnerIds */
        $learnerIds = DB::table('group_members as gm')
            ->join('telegram_groups as g', 'g.id', '=', 'gm.telegram_group_id')
            ->where('g.organization_id', $organization->id)
            ->where('gm.status', 'active')
            ->pluck('gm.learner_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        DB::afterCommit(fn () => $this->entitlements->forgetMany($learnerIds));
    }
}
