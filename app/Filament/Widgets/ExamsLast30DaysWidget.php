<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\PanelDataScope;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ExamsLast30DaysWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        $since = now()->subDays(30);
        $exams = app(PanelDataScope::class)->exams();

        $total = (clone $exams)->where('started_at', '>=', $since)->count();
        $closed = (clone $exams)->where('started_at', '>=', $since)->where('status', 'closed')->count();
        $open = (clone $exams)->where('status', 'open')->count();

        return [
            Stat::make(__('admin.widgets.exams_30d.total'), (string) $total)
                ->description(__('admin.widgets.exams_30d.closed', ['count' => $closed]))
                ->color('primary')
                ->icon('heroicon-o-trophy'),
            Stat::make(__('admin.widgets.exams_30d.open'), (string) $open)
                ->color($open > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-clock'),
        ];
    }
}
