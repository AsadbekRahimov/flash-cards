@php
    use App\Filament\Resources\Support\AdminNumbers;
    use Carbon\CarbonImmutable;
    use function Filament\Support\generate_href_html;

    /** @var list<array{week_start: string, week_end: string, cell: array{count: int, of: int, pct: float}}> $points */
    /** @var float|null $average */
    /** @var string $reportUrl */
    // The plot: x 30..470, y 20..140 of a 480 × 170 box; the scale is the values rounded out to tens, four grid lines.
    [$left, $right, $top, $bottom] = [30, 470, 20, 140];
    $values = array_map(fn (array $point): float => $point['cell']['pct'], $points);
    $low = $values === [] ? 0 : max(0, floor((min($values) - 5) / 10) * 10);
    $high = $values === [] ? 100 : min(100, ceil((max($values) + 5) / 10) * 10);
    if ($high <= $low) {
        $high = $low + 10;
    }
    $y = fn (float $value): float => round($bottom - ($value - $low) / ($high - $low) * ($bottom - $top), 1);
    $count = count($points);
    $x = fn (int $i): float => $count <= 1 ? ($left + $right) / 2 : round($left + $i * ($right - $left) / ($count - 1), 1);
    $path = implode(' ', array_map(fn (int $i, float $value): string => ($i === 0 ? 'M' : 'L').$x($i).' '.$y($value), array_keys($values), $values));
    $grid = array_map(fn (int $k): float => $low + ($high - $low) * $k / 3, [3, 2, 1, 0]);
    $fell = $count >= 2 && $values[$count - 1] < $values[$count - 2];
    // A month label at the first week of every month.
    $months = [];
    foreach ($points as $i => $point) {
        $month = CarbonImmutable::parse($point['week_start'])->translatedFormat('M');
        if ($months === [] || end($months)['label'] !== $month) {
            $months[] = ['x' => $x($i), 'label' => $month];
        }
    }
@endphp

<x-filament-widgets::widget>
    <section class="lx-card lx-block" data-testid="dashboard-retention">
        <header class="lx-block-head">
            <h2 class="lx-block-title">{{ __('admin.dashboard.retention.heading') }}</h2>
            <a {{ generate_href_html($reportUrl) }} class="lx-small lx-link">{{ __('admin.dashboard.retention.report') }}</a>
        </header>

        @if ($points === [])
            <p class="lx-muted lx-small">{{ __('admin.dashboard.retention.empty') }}</p>
        @else
            <svg
                role="img"
                aria-label="{{ __('admin.dashboard.retention.chart', ['min' => AdminNumbers::decimal(min($values), 0), 'max' => AdminNumbers::decimal(max($values), 0), 'last' => AdminNumbers::decimal($values[$count - 1], 0)]) }}"
                viewBox="0 0 480 170"
                class="lx-chart"
            >
                <g class="lx-chart-grid">
                    @foreach ($grid as $line)
                        <path d="M{{ $left }} {{ $y($line) }}H{{ $right }}" />
                    @endforeach
                </g>
                @if ($average !== null)
                    <path d="M{{ $left }} {{ $y($average) }}H{{ $right }}" class="lx-chart-average" />
                @endif
                <g class="lx-chart-labels">
                    @foreach ($grid as $line)
                        <text x="4" y="{{ $y($line) + 4 }}">{{ AdminNumbers::decimal($line, 0) }}</text>
                    @endforeach
                    @foreach ($months as $month)
                        <text x="{{ $month['x'] }}" y="162">{{ $month['label'] }}</text>
                    @endforeach
                </g>
                <path d="{{ $path }}" class="lx-chart-line" />
                <g class="lx-chart-dots">
                    @foreach ($values as $i => $value)
                        @if (! ($fell && $i === $count - 1))
                            <circle cx="{{ $x($i) }}" cy="{{ $y($value) }}" r="2.5"><title>{{ CarbonImmutable::parse($points[$i]['week_start'])->format('d.m') }}: {{ AdminNumbers::decimal($value, 1, trimZeros: true) }} % ({{ $points[$i]['cell']['count'] }} / {{ $points[$i]['cell']['of'] }})</title></circle>
                        @endif
                    @endforeach
                </g>
                @if ($fell)
                    <circle cx="{{ $x($count - 1) }}" cy="{{ $y($values[$count - 1]) }}" r="3.5" class="lx-chart-alert"><title>{{ CarbonImmutable::parse($points[$count - 1]['week_start'])->format('d.m') }}: {{ AdminNumbers::decimal($values[$count - 1], 1, trimZeros: true) }} %</title></circle>
                @endif
            </svg>

            <div class="lx-legend-row lx-small lx-muted">
                <span>{{ __('admin.dashboard.retention.fact') }}</span>
                @if ($average !== null)
                    <span>{{ __('admin.dashboard.retention.average', ['value' => AdminNumbers::decimal($average, 0)]) }}</span>
                @endif
                <span>{{ __('admin.dashboard.retention.meta', ['count' => $count]) }}</span>
                @if ($fell)
                    <span class="lx-bad">{{ __('admin.dashboard.retention.fell') }}</span>
                @endif
            </div>
        @endif
    </section>
</x-filament-widgets::widget>
