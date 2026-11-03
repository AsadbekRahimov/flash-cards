<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Billing\Services\EntitlementResolver;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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

        $organizationId = $organization->id;

        // One guarded step per learner: a dead cache stops neither the other learners nor the commit of the deleter.
        DB::afterCommit(function () use ($organizationId, $learnerIds): void {
            foreach ($learnerIds as $learnerId) {
                try {
                    $this->entitlements->forget($learnerId);
                } catch (Throwable $e) {
                    report($e);
                    Log::warning('organizations.delete_entitlement_forget_failed', [
                        'organization_id' => $organizationId,
                        'learner_id' => $learnerId,
                        'exception' => $e::class,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }
}
