<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Editing / deleting organizations by an admin (Filament `OrganizationResource`). Only an `active`
 * organization sponsors its groups (docs/12 §5.1), so a status change is audited
 * (`organization.status_changed`); the cache of the members' entitlements is dropped by
 * `OrganizationObserver` on the Eloquent save. Linking groups is {@see OrganizationGroupLinker}.
 * Who may do it is decided by `OrganizationPolicy` at the call site.
 */
final class OrganizationAdminService
{
    public const ACTION_STATUS_CHANGED = 'organization.status_changed';

    /** Not changeable after creation (`slug` is part of the identity). */
    private const EDITABLE = ['name', 'type', 'status', 'timezone', 'locale', 'settings'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Organization $organization, array $data, User $by, ?string $ip = null): Organization
    {
        return DB::transaction(function () use ($organization, $data, $by, $ip): Organization {
            $organization->fill(Arr::only($data, self::EDITABLE));

            $statusChange = $organization->isDirty('status')
                ? ['from' => $organization->getOriginal('status'), 'to' => $organization->status]
                : null;

            $organization->save();

            if ($statusChange !== null) {
                AuditLog::create([
                    'user_id' => $by->id,
                    'action' => self::ACTION_STATUS_CHANGED,
                    'entity_type' => Organization::class,
                    'entity_id' => $organization->id,
                    'ip' => $ip,
                    'meta' => ['name' => $organization->name, ...$statusChange],
                ]);
            }

            return $organization;
        });
    }

    /**
     * Why the organization must not be deleted, or null when it may: a live subscription or
     * linked groups would be orphaned (suspend it instead to stop the sponsorship).
     */
    public function deletionBlocker(Organization $organization): ?string
    {
        $hasLiveSubscription = $organization->subscriptions()
            ->whereIn('status', SubscriptionStatus::liveValues())
            ->exists();

        if ($hasLiveSubscription) {
            return 'The organization has a live subscription. Revoke it first, or suspend the organization instead of deleting it.';
        }

        if ($organization->telegramGroups()->exists()) {
            return 'The organization still has linked groups. Unlink them first, or suspend the organization instead of deleting it.';
        }

        return null;
    }
}
