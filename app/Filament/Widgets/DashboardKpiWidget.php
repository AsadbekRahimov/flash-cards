<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\AttentionList;
use App\Domain\Administration\Services\DashboardOverview;

/** The four KPI cards of the dashboard: studied today, the activation in 24 hours, D7, what needs attention. */
class DashboardKpiWidget extends DashboardBlockWidget
{
    protected string $view = 'filament.widgets.dashboard.kpis';

    protected int|string|array $columnSpan = 'full';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $overview = app(DashboardOverview::class);

        return [
            'today' => $overview->studyToday(),
            'activation' => $overview->activation(),
            'day7' => $overview->returnDay7(),
            'attention' => AttentionList::summary(app(AttentionList::class)->items()),
        ];
    }
}
