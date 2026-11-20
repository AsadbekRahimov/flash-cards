@php
    use App\Domain\Administration\Services\DashboardOverview;
    use App\Filament\Resources\Support\AdminDates;
    use App\Filament\Resources\Support\AdminNumbers;
    use Carbon\CarbonImmutable;

    /** @var array{studied: int, goal_met: int, week_ago: int, change: int|null} $today */
    /** @var array<string, mixed>|null $activation */
    /** @var array<string, mixed>|null $day7 */
    /** @var array{count: int, urgent: int, kinds: list<string>} $attention */
    $int = fn (int $value): string => number_format($value, 0, ',', ' ');
    $pct = fn (float $value): string => AdminNumbers::decimal($value, 0);
    $signed = fn (float|int $value, int $decimals = 0): string => ($value > 0 ? '+' : ($value < 0 ? '−' : '±')).AdminNumbers::decimal(abs($value), $decimals, trimZeros: true);
    $cut = fn (?array $cell): string => $cell === null ? '—' : $pct($cell['pct']).' %';
    $weekday = (int) CarbonImmutable::now(\App\Domain\Analytics\Services\RetentionReportService::REPORT_TIMEZONE)->isoWeekday();
@endphp

<x-filament-widgets::widget>
    <div class="lx-kpis" data-testid="dashboard-kpis">
        <div class="lx-card lx-kpi" data-kpi="today">
            <span class="lx-muted lx-small">{{ __('admin.dashboard.kpi.studied_today') }}</span>
            <div class="lx-kpi-line">
                <span class="lx-kpi-value">{{ $int($today['studied']) }}</span>
                @if ($today['change'] !== null)
                    <span @class(['lx-small', 'lx-good' => $today['change'] >= 0, 'lx-bad' => $today['change'] < 0])>{{ __('admin.dashboard.kpi.change', ['value' => $signed($today['change']), 'weekday' => __('admin.dashboard.kpi.weekdays.'.$weekday)]) }}</span>
                @else
                    <span class="lx-small lx-muted">{{ __('admin.dashboard.kpi.change_none') }}</span>
                @endif
            </div>
            <span class="lx-muted lx-small">{{ __('admin.dashboard.kpi.goal_met', ['count' => $int($today['goal_met'])]) }}</span>
        </div>

        <div class="lx-card lx-kpi" data-kpi="activation">
            <span class="lx-muted lx-small">{{ __('admin.dashboard.kpi.activation') }}</span>
            @if ($activation !== null)
                <div class="lx-kpi-line">
                    <span class="lx-kpi-value">{{ $pct($activation['all']['pct']) }}%</span>
                    <span class="lx-small lx-muted">{{ __('admin.dashboard.kpi.of', ['count' => $int($activation['all']['count']), 'of' => $int($activation['all']['of'])]) }}</span>
                </div>
                <span class="lx-muted lx-small">{{ __('admin.dashboard.kpi.cuts', ['ru' => $cut($activation['ru']), 'uz' => $cut($activation['uz']), 'class' => $cut($activation['class'])]) }}</span>
            @else
                <div class="lx-kpi-line"><span class="lx-kpi-value">—</span></div>
                <span class="lx-muted lx-small">{{ __('admin.dashboard.kpi.not_yet') }}</span>
            @endif
        </div>

        <div class="lx-card lx-kpi" data-kpi="day7">
            <span class="lx-muted lx-small">{{ __('admin.dashboard.kpi.return_d7') }}</span>
            @if ($day7 !== null)
                <div class="lx-kpi-line">
                    <span class="lx-kpi-value">{{ $pct($day7['cell']['pct']) }}%</span>
                    @if ($day7['change'] !== null)
                        <span @class(['lx-small', 'lx-good' => $day7['change'] > 0, 'lx-bad' => $day7['change'] < 0, 'lx-muted' => $day7['change'] == 0])>{{ __('admin.dashboard.kpi.points', ['value' => $signed($day7['change'], 1)]) }}</span>
                    @endif
                </div>
                <span class="lx-muted lx-small">
                    {{ __('admin.dashboard.kpi.cohort', ['from' => CarbonImmutable::parse($day7['week_start'])->translatedFormat('j'), 'to' => CarbonImmutable::parse($day7['week_end'])->translatedFormat('j F')]) }}@if ($day7['low'] !== null) · {{ __('admin.dashboard.kpi.interval', ['low' => $pct($day7['low']), 'high' => $pct($day7['high'])]) }}@endif
                </span>
            @else
                <div class="lx-kpi-line"><span class="lx-kpi-value">—</span></div>
                <span class="lx-muted lx-small">{{ __('admin.dashboard.retention.empty') }}</span>
            @endif
        </div>

        <div class="lx-card lx-kpi" data-kpi="attention">
            <span class="lx-muted lx-small">{{ __('admin.dashboard.kpi.attention') }}</span>
            <div class="lx-kpi-line">
                <span class="lx-kpi-value">{{ $attention['count'] }}</span>
                @if ($attention['urgent'] > 0)
                    <span class="lx-small lx-bad">{{ trans_choice('admin.dashboard.kpi.urgent', $attention['urgent'], ['count' => $attention['urgent']]) }}</span>
                @else
                    <span class="lx-small lx-muted">{{ __('admin.dashboard.kpi.no_urgent') }}</span>
                @endif
            </div>
            <span class="lx-muted lx-small">
                {{ $attention['kinds'] === [] ? __('admin.dashboard.kpi.all_clear') : implode(', ', array_map(fn (string $kind): string => __('admin.dashboard.kpi.kinds.'.$kind), $attention['kinds'])) }}
            </span>
        </div>
    </div>
</x-filament-widgets::widget>
