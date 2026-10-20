<?php

declare(strict_types=1);

namespace App\Filament\Org\Pages;

use App\Filament\Org\Widgets\OrgActivityWidget;
use App\Filament\Org\Widgets\OrgSeatsWidget;
use Filament\Pages\Dashboard as BaseDashboard;

/** Home of the cabinet (docs/13 §6.3): seats, subscription, activity of the last 7 days. */
class Dashboard extends BaseDashboard
{
    public static function getNavigationLabel(): string
    {
        return __('org.nav.dashboard');
    }

    public function getTitle(): string
    {
        return __('org.nav.dashboard');
    }

    /** @return array<class-string> */
    public function getWidgets(): array
    {
        return [
            OrgSeatsWidget::class,
            OrgActivityWidget::class,
        ];
    }

    public function getColumns(): int
    {
        return 1;
    }
}
