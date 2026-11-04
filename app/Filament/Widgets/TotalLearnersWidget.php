<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\AdminDashboardStats;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TotalLearnersWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    // No polling (docs/20 §1.11): Filament polls a stats widget every 5 s by default; the figures are cached anyway.
    protected ?string $pollingInterval = null;

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        $learners = app(AdminDashboardStats::class)->totalLearners();
        $recent = $learners['recent'];

        return [
            Stat::make(__('admin.widgets.total_learners.total'), (string) $learners['total'])
                ->color('primary')
                ->icon('heroicon-o-academic-cap'),
            Stat::make(__('admin.widgets.total_learners.active_7d'), (string) $recent)
                ->color($recent > 0 ? 'success' : 'gray')
                ->icon('heroicon-o-bolt'),
        ];
    }
}
