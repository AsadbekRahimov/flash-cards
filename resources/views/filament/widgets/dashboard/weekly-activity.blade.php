@php
    use App\Filament\Resources\Support\AdminNumbers;
    use Carbon\CarbonImmutable;

    /** @var list<array{week_start: string, reviews: int, new_words: int}> $weeks */
    // The plot: x 30..470, y 20..140 of a 480 × 170 box, thousands of answers; the top of the scale is a round number of thousands.
    [$left, $right, $top, $bottom] = [30, 470, 20, 140];
    $max = max([1, ...array_map(fn (array $week): int => $week['reviews'], $weeks)]);
    $step = max(1, (int) ceil($max / 3 / 1000)) * 1000;
    if ($max < 3000) {
        $step = max(1, (int) ceil($max / 3 / 10) * 10);
    }
    $scale = $step * 3;
    $h = fn (int $value): float => round($value / $scale * ($bottom - $top), 1);
    $count = max(1, count($weeks));
    $slot = ($right - $left) / $count;
    $width = round(min(22, $slot * 0.6), 1);
    $label = fn (int $value): string => $scale >= 3000 ? AdminNumbers::decimal($value / 1000, 0) : (string) $value;
    $empty = array_sum(array_column($weeks, 'reviews')) === 0;
@endphp

<x-filament-widgets::widget>
    <section class="lx-card lx-block" data-testid="dashboard-weekly">
        <header class="lx-block-head">
            <h2 class="lx-block-title">{{ __('admin.dashboard.weekly.heading') }}</h2>
            <span class="lx-muted lx-small">{{ __('admin.dashboard.weekly.meta', ['units' => __($scale >= 3000 ? 'admin.dashboard.weekly.thousands' : 'admin.dashboard.weekly.units'), 'count' => count($weeks)]) }}</span>
        </header>

        @if ($empty)
            <p class="lx-muted lx-small">{{ __('admin.dashboard.weekly.empty') }}</p>
        @else
            <svg
                role="img"
                aria-label="{{ __('admin.dashboard.weekly.chart', ['last' => number_format($weeks[count($weeks) - 1]['reviews'], 0, ',', ' ')]) }}"
                viewBox="0 0 480 170"
                class="lx-chart"
            >
                <g class="lx-chart-grid">
                    @foreach ([3, 2, 1, 0] as $k)
                        <path d="M{{ $left }} {{ $bottom - $h($step * $k) }}H{{ $right }}" />
                    @endforeach
                </g>
                <g class="lx-chart-labels">
                    @foreach ([3, 2, 1, 0] as $k)
                        <text x="4" y="{{ $bottom - $h($step * $k) + 4 }}">{{ $label($step * $k) }}</text>
                    @endforeach
                </g>
                @foreach ($weeks as $i => $week)
                    @php
                        $x = round($left + $i * $slot + ($slot - $width) / 2, 1);
                        $repeat = max(0, $week['reviews'] - $week['new_words']);
                        $title = CarbonImmutable::parse($week['week_start'])->format('d.m').': '.__('admin.dashboard.weekly.reviews').' '.$repeat.', '.__('admin.dashboard.weekly.new_words').' '.$week['new_words'];
                    @endphp
                    <g>
                        <title>{{ $title }}</title>
                        <rect x="{{ $x }}" y="{{ $bottom - $h($repeat) }}" width="{{ $width }}" height="{{ $h($repeat) }}" class="lx-bar-repeat" />
                        @if ($week['new_words'] > 0)
                            <rect x="{{ $x }}" y="{{ $bottom - $h($week['reviews']) }}" width="{{ $width }}" height="{{ max(0, $h($week['new_words']) - 1) }}" class="lx-bar-new" />
                        @endif
                    </g>
                @endforeach
            </svg>

            <div class="lx-legend-row lx-small lx-muted">
                <span class="lx-legend-inline"><span aria-hidden="true" class="lx-swatch lx-bar-repeat-swatch"></span>{{ __('admin.dashboard.weekly.reviews') }}</span>
                <span class="lx-legend-inline"><span aria-hidden="true" class="lx-swatch lx-bar-new-swatch"></span>{{ __('admin.dashboard.weekly.new_words') }}</span>
            </div>
        @endif
    </section>
</x-filament-widgets::widget>
