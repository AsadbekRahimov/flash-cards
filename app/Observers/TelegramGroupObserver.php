<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Organizations\Services\OrganizationMembershipService;
use App\Models\TelegramGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A group's organization link is an input of its members' organization membership
 * (docs/13 §1.3, §6.2): whatever path changes it (Filament relation manager, group edit form,
 * console) the active members join the new organization (`joined_via = group`) and leave the
 * old one when they are in none of its other groups — after commit, each in its own short
 * transaction. The members' cached entitlements are dropped as before (docs/12 §5.2).
 * Registered in AppServiceProvider. Such changes must go through Eloquent: query-builder
 * updates (`TelegramGroup::where()->update()`) bypass observers.
 */
final class TelegramGroupObserver
{
    public function __construct(
        private readonly EntitlementResolver $entitlements,
        private readonly OrganizationMembershipService $organizations,
    ) {}

    public function updated(TelegramGroup $group): void
    {
        if ($group->wasChanged('organization_id') || $group->wasChanged('status')) {
            $groupId = $group->id;

            DB::afterCommit(fn () => $this->entitlements->forgetGroup($groupId));
        }

        if ($group->wasChanged('organization_id')) {
            $previous = $group->getOriginal('organization_id');
            $previous = $previous === null ? null : (int) $previous;
            $learnerIds = $this->activeLearnerIds($group);

            DB::afterCommit(function () use ($group, $previous, $learnerIds): void {
                $this->guard('organizations.group_relink_failed', $group, function () use ($group, $previous, $learnerIds): void {
                    if ($previous !== null) {
                        $this->organizations->leaveGroupOrphans($previous, $learnerIds);
                    }

                    if ($group->organization_id !== null) {
                        $this->organizations->syncGroup($group);
                    }
                });
            });
        }
    }

    /** The cascade removes the group memberships: enumerate them now, forget after commit. */
    public function deleting(TelegramGroup $group): void
    {
        $learnerIds = $this->activeLearnerIds($group);

        DB::afterCommit(fn () => $this->entitlements->forgetMany($learnerIds));
    }

    /**
     * After the row (and its `group_members`) are gone: every `joined_via = group` member of the
     * organization who is in none of its remaining groups leaves it. Scans the organization's
     * members rather than the deleted group's rows, so it is correct whether `afterCommit` runs
     * at once (no outer transaction) or later.
     */
    public function deleted(TelegramGroup $group): void
    {
        $organizationId = $group->organization_id;

        if ($organizationId === null) {
            return;
        }

        DB::afterCommit(function () use ($group, $organizationId): void {
            $this->guard('organizations.group_delete_release_failed', $group, fn () => $this->organizations->releaseOrphans($organizationId));
        });
    }

    /** @return list<int> */
    private function activeLearnerIds(TelegramGroup $group): array
    {
        /** @var list<int> $ids */
        $ids = DB::table('group_members')
            ->where('telegram_group_id', $group->id)
            ->where('status', 'active')
            ->pluck('learner_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
    }

    /** @param callable(): mixed $hook */
    private function guard(string $failureLog, TelegramGroup $group, callable $hook): void
    {
        try {
            $hook();
        } catch (Throwable $e) {
            Log::warning($failureLog, [
                'telegram_group_id' => $group->id,
                'organization_id' => $group->organization_id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
