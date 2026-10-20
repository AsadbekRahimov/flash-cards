<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources;

use App\Domain\Organizations\Services\OrgTenantScope;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Resource;

/**
 * Base of every resource of the `/org` cabinet (docs/13 §6.3). A resource lists ONLY what
 * {@see OrgTenantScope} returns for the acting user in the current tenant; the policies of
 * `App\Policies\Org` are the second check on every record. Labels come from `lang/<locale>/org.php`
 * (the cabinet speaks the organization's language — SetOrgLocale).
 */
abstract class OrgResource extends Resource
{
    protected static function tenant(): Organization
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Organization) {
            abort(404);
        }

        return $tenant;
    }

    protected static function user(): User
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    protected static function scope(): OrgTenantScope
    {
        return app(OrgTenantScope::class);
    }

    protected static function isOwner(): bool
    {
        return static::scope()->isOwner(static::user(), static::tenant());
    }

    protected static function ip(): ?string
    {
        return request()->ip();
    }
}
