<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\PanelDataScope;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TotalStudentsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        $students = app(PanelDataScope::class)->students();
        $total = (clone $students)->count();
        $active = (clone $students)->where('is_active', true)->count();
        $recent = (clone $students)->where('last_seen_at', '>=', now()->subDays(7))->count();

        return [
            Stat::make('Total students', (string) $total)
                ->description($active.' active')
                ->color('primary')
                ->icon('heroicon-o-academic-cap'),
            Stat::make('Active last 7 days', (string) $recent)
                ->color($recent > 0 ? 'success' : 'gray')
                ->icon('heroicon-o-bolt'),
        ];
    }
}
