@php
    use App\Domain\Analytics\Services\RetentionReportService;
    use App\Filament\Resources\Support\AdminDates;
    use App\Filament\Resources\Support\AdminNumbers;
    use Carbon\CarbonImmutable;

    $report = $this->report();
    $cohorts = $report['cohorts'];
    $first = $report['first_session'];
    $segments = $report['segments'];

    // A cell is null (a dash, never 0 %) or ['count', 'of', 'pct']; the percent above, the number of learners under it.
    $cellPct = fn (mixed $cell): string => is_array($cell) ? AdminNumbers::decimal((float) $cell['pct'], 1).' %' : '—';
    $cellCount = fn (mixed $cell): string => is_array($cell) ? (string) (int) $cell['count'] : '';
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
                            <td class="lm-num">
                                <span class="rt-cell">
                                    <strong>{{ $cohort['started_pct'] === null ? '—' : AdminNumbers::decimal((float) $cohort['started_pct'], 1).' %' }}</strong>
                                    <span class="lm-muted">{{ (int) $cohort['started'] }}</span>
                                </span>
                            </td>
                            @foreach ($retentionKeys as $key)
                                @php($cell = $cohort['retention'][$key] ?? null)
                                <td class="lm-num">
                                    <span class="rt-cell {{ is_array($cell) ? '' : 'rt-dash' }}">
                                        <strong>{{ $cellPct($cell) }}</strong>
                                        <span class="lm-muted">{{ $cellCount($cell) }}</span>
                                    </span>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    <x-filament::section
        :heading="__('admin.retention.first_session.heading')"
        :description="__('admin.retention.first_session.description', ['days' => RetentionReportService::FIRST_SESSION_DAYS, 'since' => AdminDates::date(CarbonImmutable::parse((string) $first['since']))])"
    >
        <dl class="rt-metrics">
            <div>
                <dt>{{ __('admin.retention.first_session.registered') }}</dt>
                <dd>{{ (int) $first['registered'] }}</dd>
            </div>
            @foreach (['opened', 'finished', 'abandoned', 'second_24h', 'second_7d'] as $metric)
                <div>
                    <dt>{{ __('admin.retention.first_session.'.$metric) }}</dt>
                    <dd>{{ $cellPct($first[$metric]) }} @if (is_array($first[$metric]))<small>{{ (int) $first[$metric]['count'] }} / {{ (int) $first[$metric]['of'] }}</small>@endif</dd>
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
        <p class="lm-note">{{ __('admin.retention.first_session.note') }}</p>
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
                                    <span class="rt-cell {{ is_array($row[$key]) ? '' : 'rt-dash' }}">
                                        <strong>{{ $cellPct($row[$key]) }}</strong>
                                        <span class="lm-muted">{{ is_array($row[$key]) ? (int) $row[$key]['count'].' / '.(int) $row[$key]['of'] : '' }}</span>
                                    </span>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
