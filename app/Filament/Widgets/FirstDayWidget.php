<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\DashboardOverview;

/**
 * «Первый день новичка»: the learners of the latest registration week whose first 24 hours are over, split by the deepest step
 * each reached (answered a word, opened a session, finished the onboarding, nothing more), and the p75 of the open speed.
 */
class FirstDayWidget extends DashboardBlockWidget
{
    protected string $view = 'filament.widgets.dashboard.first-day';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $overview = app(DashboardOverview::class);

        return ['day' => $overview->firstDay(), 'speed' => $overview->openSpeed()];
    }
}
