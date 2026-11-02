<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Organizations\Services\GroupAssignmentCloser;
use App\Domain\Organizations\Services\OrganizationMembershipService;
use App\Domain\Organizations\Services\OrgOverviewService;
use App\Domain\Organizations\Services\OrgRequestMemo;
use App\Models\TelegramGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use WeakMap;

/**
 * A group's organization link is an input of its members' organization membership
 * (docs/13 §1.3, §6.2): whatever path changes it (Filament relation manager, group edit form,
 * console) the active members join the new organization (`joined_via = group`) and leave the
 * old one when they are in none of its other groups — after commit, each in its own short
 * transaction. The link is also an input of what the staff of either center may see (`OrgTenantScope`):
 * the per-request memory of the cabinet is dropped in the same transaction.
 * Registered in AppServiceProvider (a scoped binding: the members of a group that is being deleted are carried from `deleting` to
 * `deleted`, and Eloquent makes the observer anew for every event unless the container keeps one). Such changes must go through
 * Eloquent: query-builder updates (`TelegramGroup::where()->update()`) bypass observers.
 *
 * After the commit the steps run in ONE callback, in this order, each under its own `guard()` - a failure is logged and never
 * stops the next step, never throws out of the commit of whoever saved the group:
 *
 *  1. the members follow the group (`updated`: they leave the old center and join the new one; `deleted`: the members of the
 *     center who are in none of its groups any more are released) - the one step that needs a database, and the one a retry would
 *     not repeat, so it goes first;
 *  2. the members' cached entitlements are dropped (docs/12 §5.2). Since 2B.1 the plan does not depend on the group row: every
 *     change of a membership above raises the version of that learner's entitlement itself (`OrganizationMembershipService`: the
 *     join, the leave and the promotion from the waitlist each do it after their own commit), so this step is a belt, and a cache
 *     that cannot be reached here leaves nobody with a plan that the relink changed;
 *  3. the cached `/org` overview of both centers, the one the group left and the one it joined (docs/19 K2) - last: the overview
 *     recomputed from now on counts the members the relink moved, even when the relink stopped half way.
 *
 * A change of the status alone is only step 2.
 */
final class TelegramGroupObserver
{
    private const ENTITLEMENT_FORGET_FAILED = 'organizations.group_entitlement_forget_failed';

    /** @var WeakMap<TelegramGroup, list<int>> the active members of a group that is being deleted, by the instance that carries the delete */
    private WeakMap $members;

    public function __construct(
        private readonly EntitlementResolver $entitlements,
        private readonly OrganizationMembershipService $organizations,
        private readonly GroupAssignmentCloser $assignments,
        private readonly OrgRequestMemo $memo,
    ) {
        $this->members = new WeakMap;
    }

    public function updated(TelegramGroup $group): void
    {
        if (! $group->wasChanged('organization_id')) {
            if ($group->wasChanged('status')) {
                DB::afterCommit(fn () => $this->guard(self::ENTITLEMENT_FORGET_FAILED, $group, fn () => $this->entitlements->forgetGroup($group->id)));
            }

            return;
        }

        $this->memo->flush();

        $previous = $group->getOriginal('organization_id');
        $previous = $previous === null ? null : (int) $previous;
        $learnerIds = $this->activeLearnerIds($group);

        // In the same transaction as the change: the old center must lose the group's assignments at once.
        if ($previous !== null) {
            $this->assignments->closeForGroup($group->id, $previous);
        }

        $current = $group->organization_id === null ? null : (int) $group->organization_id;

        DB::afterCommit(function () use ($group, $previous, $current, $learnerIds): void {
            $this->guard('organizations.group_relink_failed', $group, function () use ($group, $previous, $learnerIds): void {
                if ($previous !== null) {
                    $this->organizations->leaveGroupOrphans($previous, $learnerIds);
                }

                if ($group->organization_id !== null) {
                    $this->organizations->syncGroup($group);
                }
            });

            $this->guard(self::ENTITLEMENT_FORGET_FAILED, $group, fn () => $this->entitlements->forgetGroup($group->id));

            $this->guard('organizations.group_overview_forget_failed', $group, function () use ($previous, $current): void {
                foreach (array_unique(array_filter([$previous, $current])) as $organizationId) {
                    OrgOverviewService::forget($organizationId);
                }
            });
        });
    }

    /** The cascade removes the group memberships: enumerate them now, forget after the commit of the delete (`deleted`). */
    public function deleting(TelegramGroup $group): void
    {
        $this->members[$group] = $this->activeLearnerIds($group);
    }

    /**
     * After the row (and its `group_members`) are gone: every `joined_via = group` member of the
     * organization who is in none of its remaining groups leaves it. Scans the organization's
     * members rather than the deleted group's rows, so it is correct whether `afterCommit` runs
     * at once (no outer transaction) or later.
     */
    public function deleted(TelegramGroup $group): void
    {
        $learnerIds = $this->members[$group] ?? [];
        unset($this->members[$group]);

        $organizationId = $group->organization_id === null ? null : (int) $group->organization_id;

        if ($organizationId === null && $learnerIds === []) {
            return;
        }

        if ($organizationId !== null) {
            $this->memo->flush();
        }

        DB::afterCommit(function () use ($group, $organizationId, $learnerIds): void {
            if ($organizationId !== null) {
                $this->guard('organizations.group_delete_release_failed', $group, fn () => $this->organizations->releaseOrphans($organizationId));
            }

            $this->guard(self::ENTITLEMENT_FORGET_FAILED, $group, fn () => $this->entitlements->forgetMany($learnerIds));

            if ($organizationId !== null) {
                $this->guard('organizations.group_overview_forget_failed', $group, fn () => OrgOverviewService::forget($organizationId));
            }
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
