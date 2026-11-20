@php
    use App\Filament\Resources\Support\AdminNumbers;
    use Carbon\CarbonImmutable;

    /** @var array{week_start: string, week_end: string, size: int, answered: int, opened: int, onboarded: int, rest: int}|null $day */
    /** @var array<string, array{n: int, p50: int|null, p75: int|null, p95: int|null}> $speed */
    $steps = ['answered' => 'lx-step-1', 'opened' => 'lx-step-2', 'onboarded' => 'lx-step-3', 'rest' => 'lx-step-4'];
    $speedParts = [];
    foreach ($speed as $platform => $measure) {
        if ($measure['p75'] !== null) {
            $speedParts[] = __('admin.dashboard.first_day.speed_part', [
                'platform' => __('admin.dashboard.first_day.platforms.'.$platform),
                'seconds' => AdminNumbers::decimal($measure['p75'] / 1000, 1),
            ]);
        }
    }
@endphp

<x-filament-widgets::widget>
    <section class="lx-card lx-block" data-testid="dashboard-first-day">
        <header class="lx-block-head">
            <h2 class="lx-block-title">{{ __('admin.dashboard.first_day.heading') }}</h2>
            @if ($day !== null)
                <span class="lx-muted lx-small">{{ trans_choice('admin.dashboard.registrations', $day['size'], ['count' => $day['size']]) }}</span>
            @endif
        </header>

        @if ($day === null)
            <p class="lx-muted lx-small">{{ __('admin.dashboard.first_day.empty') }}</p>
        @else
            <div
                class="lx-stack-bar"
                role="img"
                aria-label="{{ __('admin.dashboard.first_day.chart', ['parts' => implode(', ', array_map(fn (string $step): string => __('admin.dashboard.first_day.steps.'.$step).' '.$day[$step], array_keys($steps)))]) }}"
            >
                @foreach ($steps as $step => $class)
                    @if ($day[$step] > 0)
                        <div class="{{ $class }}" style="width: {{ round($day[$step] / $day['size'] * 100, 2) }}%"></div>
                    @endif
                @endforeach
            </div>

            <div class="lx-legend-grid">
                @foreach ($steps as $step => $class)
                    <div class="lx-legend-item" data-step="{{ $step }}">
                        <span aria-hidden="true" class="lx-swatch {{ $class }}"></span>
                        <span class="lx-grow">{{ __('admin.dashboard.first_day.steps.'.$step) }}</span>
                        <span class="lx-mono lx-small">{{ $day[$step] }}</span>
                    </div>
                @endforeach
            </div>

            <p class="lx-muted lx-small">{{ __('admin.dashboard.first_day.cohort', ['from' => CarbonImmutable::parse($day['week_start'])->translatedFormat('j'), 'to' => CarbonImmutable::parse($day['week_end'])->translatedFormat('j F')]) }}</p>
        @endif

        <p class="lx-muted lx-small lx-block-foot">
            {{ $speedParts === [] ? __('admin.dashboard.first_day.speed_none') : __('admin.dashboard.first_day.speed', ['parts' => implode(' · ', $speedParts)]) }}
        </p>
    </section>
</x-filament-widgets::widget>
