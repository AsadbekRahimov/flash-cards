@php
    use App\Domain\Analytics\Services\RetentionReportService;
    use App\Domain\Analytics\Support\WilsonInterval;
    use App\Filament\Resources\Support\AdminDates;
    use App\Filament\Resources\Support\AdminNumbers;
    use Carbon\CarbonImmutable;

    $report = $this->report();
    $cohorts = $report['cohorts'];
    $first = $report['first_session'];
    $segments = $report['segments'];

    // A cell is null (a dash, never 0 %) or ['count', 'of', 'pct']; the percent above, "count / of" under it and, for a base
    // under WilsonInterval::SMALL_BASE, the Wilson 95 % interval under that (audit 2026-11-18: show the uncertainty of small cohorts).
    $cellPct = fn (mixed $cell): string => is_array($cell) ? AdminNumbers::decimal((float) $cell['pct'], 1).' %' : '—';
    $cellCount = fn (mixed $cell): string => is_array($cell) ? (int) $cell['count'].' / '.(int) $cell['of'] : '';
    $cellCi = function (mixed $cell): string {
        $interval = is_array($cell) ? WilsonInterval::forSmallBase((int) $cell['count'], (int) $cell['of']) : null;

        return $interval === null ? '' : __('admin.retention.interval', [
            'low' => AdminNumbers::decimal($interval['low'], 1),
            'high' => AdminNumbers::decimal($interval['high'], 1),
        ]);
    };
    $startedCell = fn (array $cohort): ?array => $cohort['started_pct'] === null
        ? null
        : ['count' => (int) $cohort['started'], 'of' => (int) $cohort['size'], 'pct' => (float) $cohort['started_pct']];
    $seconds = function (mixed $value): string {
        if ($value === null) {
            return '—';
        }
        $total = (int) round((float) $value);

        return $total < 60
            ? __('admin.retention.first_session.seconds', ['seconds' => $total])
            : __('admin.retention.first_session.minutes', ['minutes' => intdiv($total, 60), 'seconds' => $total % 60]);
    };
    $days = RetentionReportService::DAYS;
    $retentionKeys = [...array_map(fn (int $d): string => 'd'.$d, $days), 'within'];
@endphp

<x-filament-panels::page>
    @include('filament.pages.partials.league-month-styles')
    @once
        <style>
            /* Cohort table: narrow numeric columns so that twelve weeks fit a 1280 px screen without a horizontal scroll. */
            .rt-table th, .rt-table td { padding-inline: 0.375rem; }
            .rt-table .lm-num { text-align: center; }
            .rt-table th.lm-num { vertical-align: bottom; }
            .rt-cell { display: flex; flex-direction: column; align-items: center; line-height: 1.1rem; }
            .rt-cell strong { font-weight: 600; }
            .rt-cell .lm-muted { line-height: 0.875rem; }
            .rt-cell .rt-ci { font-size: 0.6875rem; opacity: 0.55; white-space: nowrap; }
            .rt-dash { opacity: 0.45; }
            .rt-help { display: flex; flex-direction: column; gap: 0.375rem; margin: 0; padding-inline-start: 1.1rem; list-style: disc; font-size: 0.875rem; }
            .rt-updated { margin: 0; font-size: 0.8125rem; opacity: 0.65; }
            .rt-metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 1rem 1.5rem; margin: 0; }
            .rt-metrics > div { display: flex; flex-direction: column; gap: 0.125rem; min-width: 0; }
            .rt-metrics dt { font-size: 0.8125rem; opacity: 0.65; }
            .rt-metrics dd { margin: 0; font-size: 1.125rem; font-weight: 600; }
            .rt-metrics dd small { font-size: 0.75rem; font-weight: 400; opacity: 0.65; }
        </style>
    @endonce

    <p class="rt-updated">{{ __('admin.retention.updated', ['when' => AdminDates::dateTime(CarbonImmutable::parse((string) $report['generated_at']))]) }}</p>

    <x-filament::section :heading="__('admin.retention.help.heading')" collapsible>
        <ul class="rt-help">
            <li>{{ __('admin.retention.help.studied') }}</li>
            <li>{{ __('admin.retention.help.dn') }}</li>
            <li>{{ __('admin.retention.help.dash') }}</li>
            <li>{{ __('admin.retention.help.weeks') }}</li>
            <li>{{ __('admin.retention.help.interval', ['base' => WilsonInterval::SMALL_BASE]) }}</li>
            <li>{{ __('admin.retention.help.activation') }}</li>
            <li>{{ __('admin.retention.help.game') }}</li>
            <li>{{ __('admin.retention.help.benchmarks') }}</li>
        </ul>
    </x-filament::section>

    <x-filament::section :heading="__('admin.retention.cohorts.heading')">
        @if (collect($cohorts)->sum('size') === 0)
            <p class="lm-text">{{ __('admin.retention.cohorts.empty') }}</p>
        @else
            <table class="lm-table rt-table">
                <thead>
                    <tr>
                        <th>{{ __('admin.retention.cohorts.columns.week') }}</th>
                        <th class="lm-num">{{ __('admin.retention.cohorts.columns.size') }}</th>
                        <th class="lm-num">{{ __('admin.retention.cohorts.columns.onboarded') }}</th>
                        <th class="lm-num" title="{{ __('admin.retention.cohorts.started_help') }}">{{ __('admin.retention.cohorts.columns.started') }}</th>
                        @foreach ($days as $day)
                            <th class="lm-num">{{ __('admin.retention.cohorts.columns.d'.$day) }}</th>
                        @endforeach
                        <th class="lm-num">{{ __('admin.retention.cohorts.columns.within') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($cohorts as $cohort)
                        <tr wire:key="cohort-{{ $cohort['week_start'] }}">
                            <td class="lm-nowrap lm-small">
                                <strong>{{ AdminDates::date(CarbonImmutable::parse((string) $cohort['week_start'])) }}</strong>
                                <div class="lm-muted">
                                    @if ($cohort['current'])
                                        {{ __('admin.retention.cohorts.current') }}
                                    @else
                                        – {{ AdminDates::date(CarbonImmutable::parse((string) $cohort['week_end'])) }}
                                    @endif
                                </div>
                            </td>
                            <td class="lm-num">{{ (int) $cohort['size'] }}</td>
                            <td class="lm-num">{{ (int) $cohort['onboarded'] }}</td>
                            @foreach ([$startedCell($cohort), ...array_map(fn (string $key): mixed => $cohort['retention'][$key] ?? null, $retentionKeys)] as $cell)
                                <td class="lm-num">
                                    @include('filament.pages.partials.retention-cell', ['cell' => $cell])
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    @if (collect($cohorts)->sum('size') > 0)
        <x-filament::section
            :heading="__('admin.retention.activation.heading')"
            :description="__('admin.retention.activation.description', ['hours' => RetentionReportService::ACTIVATION_HOURS])"
        >
            <table class="lm-table rt-table">
                <thead>
                    <tr>
                        <th>{{ __('admin.retention.cohorts.columns.week') }}</th>
                        <th class="lm-num">{{ __('admin.retention.cohorts.columns.size') }}</th>
                        @foreach (RetentionReportService::ACTIVATION_CUTS as $cut)
                            <th class="lm-num">{{ __('admin.retention.activation.columns.'.$cut) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($cohorts as $cohort)
                        <tr wire:key="activation-{{ $cohort['week_start'] }}">
                            <td class="lm-nowrap lm-small"><strong>{{ AdminDates::date(CarbonImmutable::parse((string) $cohort['week_start'])) }}</strong></td>
                            <td class="lm-num">{{ (int) $cohort['size'] }}</td>
                            @foreach (RetentionReportService::ACTIVATION_CUTS as $cut)
                                <td class="lm-num">
                                    @include('filament.pages.partials.retention-cell', ['cell' => $cohort['activation'][$cut] ?? null])
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="lm-note">{{ __('admin.retention.activation.note', ['hours' => RetentionReportService::ACTIVATION_HOURS]) }}</p>
        </x-filament::section>

        <x-filament::section
            :heading="__('admin.retention.game.heading')"
            :description="__('admin.retention.game.description')"
        >
            <table class="lm-table rt-table">
                <thead>
                    <tr>
                        <th>{{ __('admin.retention.cohorts.columns.week') }}</th>
                        <th class="lm-num">{{ __('admin.retention.cohorts.columns.size') }}</th>
                        <th>{{ __('admin.retention.game.columns.kind') }}</th>
                        @foreach (RetentionReportService::GAME_DAYS as $day)
                            <th class="lm-num">{{ __('admin.retention.cohorts.columns.d'.$day) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($cohorts as $cohort)
                        @foreach (['study' => $cohort['retention'], 'game' => $cohort['game']] as $kind => $cells)
                            <tr wire:key="game-{{ $cohort['week_start'] }}-{{ $kind }}">
                                @if ($kind === 'study')
                                    <td class="lm-nowrap lm-small" rowspan="2"><strong>{{ AdminDates::date(CarbonImmutable::parse((string) $cohort['week_start'])) }}</strong></td>
                                    <td class="lm-num" rowspan="2">{{ (int) $cohort['size'] }}</td>
                                @endif
                                <td class="lm-nowrap lm-small">{{ __('admin.retention.game.rows.'.$kind) }}</td>
                                @foreach (RetentionReportService::GAME_DAYS as $day)
                                    <td class="lm-num">
                                        @include('filament.pages.partials.retention-cell', ['cell' => $cells['d'.$day] ?? null])
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
            <p class="lm-note">{{ __('admin.retention.game.note') }}</p>
        </x-filament::section>
    @endif

    <x-filament::section
        :heading="__('admin.retention.first_session.heading')"
        :description="__('admin.retention.first_session.description', ['days' => RetentionReportService::FIRST_SESSION_DAYS, 'since' => AdminDates::date(CarbonImmutable::parse((string) $first['since']))])"
    >
        <dl class="rt-metrics">
            <div>
                <dt>{{ __('admin.retention.first_session.registered') }}</dt>
                <dd>{{ (int) $first['registered'] }}</dd>
            </div>
            @foreach (['opened', 'finished', 'abandoned', 'round_again', 'second_24h', 'second_7d'] as $metric)
                <div>
                    <dt>{{ __('admin.retention.first_session.'.$metric, ['minutes' => RetentionReportService::ROUND_GAP_MINUTES]) }}</dt>
                    <dd>{{ $cellPct($first[$metric]) }} @if (is_array($first[$metric]))<small>{{ $cellCount($first[$metric]) }}@if ($cellCi($first[$metric]) !== '') · {{ $cellCi($first[$metric]) }}@endif</small>@endif</dd>
                </div>
            @endforeach
            <div>
                <dt>{{ __('admin.retention.first_session.in_progress') }}</dt>
                <dd>{{ (int) $first['in_progress'] }}</dd>
            </div>
            <div>
                <dt>{{ __('admin.retention.first_session.median_cards') }}</dt>
                <dd>{{ $first['median_cards'] === null ? '—' : AdminNumbers::decimal((float) $first['median_cards'], 1, trimZeros: true) }}</dd>
            </div>
            <div>
                <dt>{{ __('admin.retention.first_session.median_seconds') }}</dt>
                <dd>{{ $seconds($first['median_seconds']) }}</dd>
            </div>
        </dl>
        <p class="lm-note">{{ __('admin.retention.first_session.note', ['minutes' => RetentionReportService::ROUND_GAP_MINUTES]) }}</p>
    </x-filament::section>

    <x-filament::section
        :heading="__('admin.retention.segments.heading')"
        :description="__('admin.retention.segments.description', ['weeks' => RetentionReportService::SEGMENT_WEEKS])"
    >
        <table class="lm-table rt-table">
            <thead>
                <tr>
                    <th>{{ __('admin.retention.segments.columns.segment') }}</th>
                    <th class="lm-num">{{ __('admin.retention.segments.columns.size') }}</th>
                    <th class="lm-num">{{ __('admin.retention.segments.columns.d1') }}</th>
                    <th class="lm-num">{{ __('admin.retention.segments.columns.d7') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach (['locale', 'trial'] as $group)
                    @foreach ($segments[$group] as $row)
                        <tr wire:key="segment-{{ $group }}-{{ $row['key'] }}">
                            <td class="lm-nowrap">{{ __('admin.retention.segments.'.$group.'.'.$row['key']) }}</td>
                            <td class="lm-num">{{ (int) $row['size'] }}</td>
                            @foreach (['d1', 'd7'] as $key)
                                <td class="lm-num">
                                    @include('filament.pages.partials.retention-cell', ['cell' => $row[$key]])
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
