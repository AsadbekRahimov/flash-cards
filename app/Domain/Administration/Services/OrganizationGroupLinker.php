<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\TelegramGroup;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Links / unlinks a Telegram group to an organization: an active group of an organization with a
 * live subscription gives its members sponsored Pro (docs/12 §5.1). The change goes through
 * Eloquent `save()` so `TelegramGroupObserver` drops the members' entitlement cache (§5.2); both
 * directions are audited (`organization.linked_group` / `organization.unlinked_group`).
 * Who may do it is decided by `OrganizationPolicy::update` at the call site (superadmin) or by
 * OrgGroupLinkService (owner of the center, after the `getChatMember` check). The row is read
 * without global scopes: inside the `/org` panel Filament's tenancy scope would hide a group that
 * is not yet the tenant's — the whole point of linking it.
 */
final class OrganizationGroupLinker
{
    public const ACTION_LINKED = 'organization.linked_group';

    public const ACTION_UNLINKED = 'organization.unlinked_group';

    /**
     * @throws DomainException the group already belongs to another organization (unlink it there first)
     */
    public function link(Organization $organization, TelegramGroup $group, User $by, ?string $ip = null): void
    {
        DB::transaction(function () use ($organization, $group, $by, $ip): void {
            /** @var TelegramGroup $current */
            $current = TelegramGroup::query()->withoutGlobalScopes()->whereKey($group->id)->lockForUpdate()->firstOrFail();

            if ($current->organization_id === $organization->id) {
                return;
            }

            if ($current->organization_id !== null) {
                throw new DomainException('The group already belongs to another organization.');
            }

            $current->organization_id = $organization->id;
            $current->save();

            $this->audit(self::ACTION_LINKED, $organization, $current, $by, $ip);
        });
    }

    public function unlink(Organization $organization, TelegramGroup $group, User $by, ?string $ip = null): void
    {
        DB::transaction(function () use ($organization, $group, $by, $ip): void {
            /** @var TelegramGroup $current */
            $current = TelegramGroup::query()->withoutGlobalScopes()->whereKey($group->id)->lockForUpdate()->firstOrFail();

            if ($current->organization_id !== $organization->id) {
                return;
            }

            $current->organization_id = null;
            $current->save();

            $this->audit(self::ACTION_UNLINKED, $organization, $current, $by, $ip);
        });
    }

    private function audit(string $action, Organization $organization, TelegramGroup $group, User $by, ?string $ip): void
    {
        AuditLog::create([
            'user_id' => $by->id,
            'action' => $action,
            'entity_type' => Organization::class,
            'entity_id' => $organization->id,
            'ip' => $ip,
            'meta' => ['telegram_group_id' => $group->id, 'chat_id' => $group->chat_id, 'title' => $group->title],
        ]);
    }
}
