<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\TrainingReview;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class ActivityChartWidget extends ChartWidget
{
    protected ?string $heading = 'Training activity — last 30 days';

    protected static ?int $sort = 3;

    /** @return array{datasets: array<int, array<string, mixed>>, labels: array<int, string>} */
    protected function getData(): array
    {
        $since = Carbon::today()->subDays(29);

        /** @var array<string, int> $counts */
        $counts = TrainingReview::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) AS d, COUNT(*) AS c')
            ->groupBy('d')
            ->pluck('c', 'd')
            ->map(fn ($v): int => (int) $v)
            ->all();

        $labels = [];
        $values = [];
        for ($i = 0; $i < 30; $i++) {
            $date = $since->copy()->addDays($i);
            $key = $date->toDateString();
            $labels[] = $date->format('M d');
            $values[] = $counts[$key] ?? 0;
        }

        return [
            'datasets' => [[
                'label' => 'Reviews',
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
