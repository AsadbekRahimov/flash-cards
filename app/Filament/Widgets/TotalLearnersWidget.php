<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\PanelDataScope;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TotalLearnersWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        $learners = app(PanelDataScope::class)->learners();
        $total = (clone $learners)->count();
        $recent = (clone $learners)->where('last_seen_at', '>=', now()->subDays(7))->count();

        return [
            Stat::make(__('admin.widgets.total_learners.total'), (string) $total)
                ->color('primary')
                ->icon('heroicon-o-academic-cap'),
            Stat::make(__('admin.widgets.total_learners.active_7d'), (string) $recent)
                ->color($recent > 0 ? 'success' : 'gray')
                ->icon('heroicon-o-bolt'),
        ];
    }
}
