<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Concerns\RequiresActiveAdmin;
use Filament\Widgets\Widget;

/**
 * A block of the tab «Учёба» of the dashboard (the design of 2026-11): a card of its own view, platform figures, so an active admin
 * only — hidden from anybody else and refused on a direct Livewire mount or update ({@see RequiresActiveAdmin}). The figures come
 * from a cached domain service ({@see \App\Domain\Administration\Services\DashboardOverview} and the services it reads): a warm
 * open runs no query. Lazy, no polling (docs/20 §1.11).
 */
abstract class DashboardBlockWidget extends Widget
{
    use RequiresActiveAdmin;

    public static function canView(): bool
    {
        return self::isActiveAdmin();
    }
}
