<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Billing\Services\EntitlementResolver;
use App\Models\TelegramGroup;
use Illuminate\Support\Facades\DB;

/**
 * A group's organization link or status is an input of its members' entitlement
 * (sponsored Pro, docs/12 §5.1). Whatever path changes it (Filament relation
 * manager, group edit form, enable / disable action) the members' cached
 * entitlements are dropped (docs/12 §5.2). Registered in AppServiceProvider.
 *
 * Query-builder updates (`TelegramGroup::where()->update()`) bypass observers;
 * the only such path today is the bot leaving a group (MyChatMemberHandler),
 * where the TTL-bounded staleness is acceptable (docs/12 §5.2).
 */
final class TelegramGroupObserver
{
    public function __construct(private readonly EntitlementResolver $entitlements) {}

    public function updated(TelegramGroup $group): void
    {
        if ($group->wasChanged('organization_id') || $group->wasChanged('status')) {
            $groupId = $group->id;

            DB::afterCommit(fn () => $this->entitlements->forgetGroup($groupId));
        }
    }

    /** The cascade removes the memberships: enumerate them now, forget after commit. */
    public function deleting(TelegramGroup $group): void
    {
        /** @var list<int> $learnerIds */
        $learnerIds = DB::table('group_members')
            ->where('telegram_group_id', $group->id)
            ->where('status', 'active')
            ->pluck('learner_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        DB::afterCommit(fn () => $this->entitlements->forgetMany($learnerIds));
    }
}
