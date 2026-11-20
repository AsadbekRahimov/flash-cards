<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\DashboardOverview;
use App\Filament\Pages\Dashboard;

/** «Путь к Pro · Stars»: offer seen → payment started → paid → studied within 7 days, from the Pro funnel of 30 days. */
class ProPathWidget extends DashboardBlockWidget
{
    protected string $view = 'filament.widgets.dashboard.pro-path';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'path' => app(DashboardOverview::class)->proPath(),
            'reportUrl' => Dashboard::getUrl(['tab' => Dashboard::TAB_MONEY]),
        ];
    }
}
