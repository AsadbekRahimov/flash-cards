<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\DashboardOverview;
use App\Filament\Pages\RetentionReport;

/** «Возврат на 7-й день, %» by registration week: the line, the share of all the weeks dashed, the last week red when it fell. */
class RetentionTrendWidget extends DashboardBlockWidget
{
    protected string $view = 'filament.widgets.dashboard.retention-trend';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $points = app(DashboardOverview::class)->day7Trend();

        return [
            'points' => $points,
            'average' => DashboardOverview::day7Average($points),
            'reportUrl' => RetentionReport::getUrl(),
        ];
    }
}
