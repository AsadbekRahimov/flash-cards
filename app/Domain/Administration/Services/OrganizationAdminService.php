<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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

    /** Attribute names of the facts a list of organizations carries for {@see deletionBlocker()}. */
    public const LIVE_SUBSCRIPTION_FACT = 'has_live_subscription';

    public const GROUPS_COUNT_FACT = 'telegram_groups_count';

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
     * The two facts {@see deletionBlocker()} reads, added to the query of a LIST of organizations as subselects of the page
     * query: otherwise the delete button of every row asks the database for them, several times (docs/20 §1.3, A11).
     * `has_live_subscription` is a boolean, `telegram_groups_count` the number of linked groups.
     *
     * @param  Builder<Organization>  $query
     * @return Builder<Organization>
     */
    public function withDeletionFacts(Builder $query): Builder
    {
        return $query
            ->withExists(['subscriptions as '.self::LIVE_SUBSCRIPTION_FACT => fn (Builder $live): Builder => $live->whereIn('status', SubscriptionStatus::liveValues())])
            ->withCount('telegramGroups as '.self::GROUPS_COUNT_FACT);
    }

    /**
     * Why the organization must not be deleted, or null when it may: a live subscription or
     * linked groups would be orphaned (suspend it instead to stop the sponsorship).
     *
     * A row of the list already carries both facts ({@see withDeletionFacts()}); any other organization (the edit page)
     * asks the database.
     */
    public function deletionBlocker(Organization $organization): ?string
    {
        // The facts are looked up by key, not read as attributes: an organization loaded without them (the edit page) must ask
        // the database, not throw under `preventAccessingMissingAttributes`.
        $attributes = $organization->getAttributes();

        $hasLiveSubscription = array_key_exists(self::LIVE_SUBSCRIPTION_FACT, $attributes)
            ? (bool) $attributes[self::LIVE_SUBSCRIPTION_FACT]
            : $organization->subscriptions()
                ->whereIn('status', SubscriptionStatus::liveValues())
                ->exists();

        if ($hasLiveSubscription) {
            return 'The organization has a live subscription. Revoke it first, or suspend the organization instead of deleting it.';
        }

        $hasGroups = array_key_exists(self::GROUPS_COUNT_FACT, $attributes)
            ? (int) $attributes[self::GROUPS_COUNT_FACT] > 0
            : $organization->telegramGroups()->exists();

        if ($hasGroups) {
            return 'The organization still has linked groups. Unlink them first, or suspend the organization instead of deleting it.';
        }

        return null;
    }
}
