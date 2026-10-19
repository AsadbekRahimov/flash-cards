<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Billing\Services\EntitlementResolver;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Only an `active` organization sponsors its seated members (docs/12 §5.1, docs/13 §1.3):
 * suspending or deleting one drops the cached entitlement of every active member.
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

    /** `organization_members` are removed by the FK cascade: enumerate members now, forget after commit. */
    public function deleting(Organization $organization): void
    {
        /** @var list<int> $learnerIds */
        $learnerIds = DB::table('organization_members')
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->pluck('learner_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        DB::afterCommit(fn () => $this->entitlements->forgetMany($learnerIds));
    }
}
