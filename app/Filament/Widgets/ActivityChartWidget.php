<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\AdminDashboardStats;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class ActivityChartWidget extends ChartWidget
{
    protected static ?int $sort = 3;

    // No polling (docs/20 §1.11): Filament polls a chart widget every 5 s by default; the figures are cached anyway.
    protected ?string $pollingInterval = null;

    public function getHeading(): string
    {
        return __('admin.widgets.activity_chart.heading');
    }

    /** @return array{datasets: array<int, array<string, mixed>>, labels: array<int, string>} */
    protected function getData(): array
    {
        // 30 numbers from `today - 29 days` to today (the cached part); the labels are the viewer's language and stay here.
        $values = app(AdminDashboardStats::class)->activity30d();
        $since = Carbon::today()->subDays(29);

        $labels = [];
        foreach (array_keys($values) as $i) {
            $labels[] = $since->copy()->addDays($i)->translatedFormat('j M');
        }

        return [
            'datasets' => [[
                'label' => __('admin.widgets.activity_chart.dataset'),
                'data' => $values,
                'borderColor' => '#f59e0b',
                'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
