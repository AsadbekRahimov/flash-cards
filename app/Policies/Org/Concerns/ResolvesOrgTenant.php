<?php

declare(strict_types=1);

namespace App\Policies\Org\Concerns;

use App\Domain\Organizations\Services\OrgTenantScope;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * The tenant of the current `/org` request. Every tenant-aware policy denies when there is no
 * tenant (a cabinet page outside a tenant URL never shows records) or the user is not staff of it.
 */
trait ResolvesOrgTenant
{
    protected function tenant(): ?Organization
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization ? $tenant : null;
    }

    protected function scope(): OrgTenantScope
    {
        return app(OrgTenantScope::class);
    }

    /** The current tenant when the user is its staff (any role), otherwise null. */
    protected function staffTenant(User $user): ?Organization
    {
        $tenant = $this->tenant();

        return $tenant !== null && $user->is_active && $this->scope()->isStaff($user, $tenant) ? $tenant : null;
    }

    /** The current tenant when the user is its owner, otherwise null. */
    protected function ownerTenant(User $user): ?Organization
    {
        $tenant = $this->tenant();

        return $tenant !== null && $user->is_active && $this->scope()->isOwner($user, $tenant) ? $tenant : null;
    }
}
