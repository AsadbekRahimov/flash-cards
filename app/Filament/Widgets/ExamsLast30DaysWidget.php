<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\AdminDashboardStats;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ExamsLast30DaysWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    // No polling (docs/20 §1.11): Filament polls a stats widget every 5 s by default; the figures are cached anyway.
    protected ?string $pollingInterval = null;

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        $exams = app(AdminDashboardStats::class)->exams30d();
        $open = $exams['open'];

        return [
            Stat::make(__('admin.widgets.exams_30d.total'), (string) $exams['total'])
                ->description(__('admin.widgets.exams_30d.closed', ['count' => $exams['closed']]))
                ->color('primary')
                ->icon('heroicon-o-trophy'),
            Stat::make(__('admin.widgets.exams_30d.open'), (string) $open)
                ->color($open > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-clock'),
        ];
    }
}
