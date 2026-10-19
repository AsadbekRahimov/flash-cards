<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Platform data (docs/12 §4.4) is for an active admin only. Hiding a widget from the dashboard
 * (`canView` / `canViewForRecord`) is not enough: a Livewire component can be mounted or updated directly, so the check
 * also runs in the Livewire `boot` hook of EVERY request (mount and each later update) and answers 403.
 */
trait RequiresActiveAdmin
{
    public static function isActiveAdmin(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->is_active === true && $user->hasRole('admin');
    }

    /** Livewire calls boot<TraitName>() on every request of a component that uses the trait. */
    public function bootRequiresActiveAdmin(): void
    {
        abort_unless(self::isActiveAdmin(), 403);
    }
}
