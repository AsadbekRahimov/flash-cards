<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Analytics\Services\WeeklyActivityStats;

/** «Занятия по неделям»: the answers of all learners per week of the last 12, repetitions and new words stacked. */
class WeeklyActivityWidget extends DashboardBlockWidget
{
    protected string $view = 'filament.widgets.dashboard.weekly-activity';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['weeks' => app(WeeklyActivityStats::class)->weeks()];
    }
}
