<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\ExamSession;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ExamsLast30DaysWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        $since = now()->subDays(30);

        $total = ExamSession::where('started_at', '>=', $since)->count();
        $closed = ExamSession::where('started_at', '>=', $since)->where('status', 'closed')->count();
        $open = ExamSession::where('status', 'open')->count();

        return [
            Stat::make('Exams last 30 days', (string) $total)
                ->description("{$closed} closed")
                ->color('primary')
                ->icon('heroicon-o-trophy'),
            Stat::make('Open exams now', (string) $open)
                ->color($open > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-clock'),
        ];
    }
}
