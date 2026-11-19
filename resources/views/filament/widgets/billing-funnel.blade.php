@php
    use App\Domain\Billing\Services\BillingFunnelService;
    use App\Filament\Resources\Support\AdminDates;
    use App\Filament\Resources\Support\AdminNumbers;
    use Carbon\CarbonImmutable;

    /** @var array<string, mixed> $report */
    $all = BillingFunnelService::ALL;
    // A label of a closed list, or the raw value when the list does not know it (a new provider / placement before its caption).
    $label = function (string $group, string $value): string {
        $key = 'admin.widgets.billing_funnel.'.$group.'.'.$value;
        $text = __($key);

        return is_string($text) && $text !== $key ? $text : $value;
    };
    // "n / of · pct %": the step and the step before it; a dash when nobody reached the step before.
    $step = fn (int $count, int $of): string => $of > 0
        ? $count.' / '.$of.' · '.AdminNumbers::decimal($count / $of * 100, 1, trimZeros: true).' %'
        : ($count > 0 ? (string) $count : '—');
    $isEmpty = $report['funnel'] === [] || (array_sum(array_column($report['funnel'], 'viewed')) === 0 && $report['outside'] === [] && $report['orders'] === []);
@endphp

<x-filament-widgets::widget>
    @include('filament.pages.partials.league-month-styles')

    <x-filament::section
        :heading="__('admin.widgets.billing_funnel.heading', ['days' => $report['days']])"
        :description="__('admin.widgets.billing_funnel.updated', ['when' => AdminDates::dateTime(CarbonImmutable::parse($report['generated_at']))])"
        icon="heroicon-o-funnel"
        collapsible
    >
        @if ($isEmpty)
            <p class="lm-muted" data-testid="billing-funnel-empty">{{ __('admin.widgets.billing_funnel.empty') }}</p>
        @else
            <div class="lm-scroll">
                <table class="lm-table" data-testid="billing-funnel">
                    <thead>
                        <tr>
                            <th>{{ __('admin.widgets.billing_funnel.columns.provider') }}</th>
                            <th>{{ __('admin.widgets.billing_funnel.columns.locale') }}</th>
                            <th class="lm-num">{{ __('admin.widgets.billing_funnel.columns.viewed') }}</th>
                            <th class="lm-num">{{ __('admin.widgets.billing_funnel.columns.started') }}</th>
                            <th class="lm-num">{{ __('admin.widgets.billing_funnel.columns.paid') }}</th>
                            <th class="lm-num">{{ __('admin.widgets.billing_funnel.columns.studied') }}</th>
                            <th class="lm-num">{{ __('admin.widgets.billing_funnel.columns.lost_before') }}</th>
                            <th class="lm-num">{{ __('admin.widgets.billing_funnel.columns.lost_during') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['funnel'] as $row)
                            <tr wire:key="bf-{{ $row['provider'] }}-{{ $row['locale'] }}" data-row="{{ $row['provider'] }}:{{ $row['locale'] }}">
                                <td class="lm-nowrap" @if ($row['locale'] === $all) style="font-weight: 600;" @endif>{{ $row['locale'] === $all ? $label('providers', $row['provider']) : '' }}</td>
                                <td class="lm-nowrap">{{ $label('locales', $row['locale']) }}</td>
                                <td class="lm-num">{{ $row['viewed'] }}</td>
                                <td class="lm-num lm-nowrap">{{ $step($row['started'], $row['viewed']) }}</td>
                                <td class="lm-num lm-nowrap">{{ $step($row['paid'], $row['started']) }}</td>
                                <td class="lm-num lm-nowrap">
                                    {{-- Among the payers whose 7 days are over: the maturing ones are not in the share yet (the note says how many). --}}
                                    {{ $step($row['studied'], max(0, $row['paid'] - $row['maturing'])) }}
                                    @if ($row['maturing'] > 0)
                                        <div class="lm-muted">{{ __('admin.widgets.billing_funnel.maturing', ['count' => $row['maturing']]) }}</div>
                                    @endif
                                </td>
                                {{-- A provider's row would count those who started with another provider as lost: the loss is over all providers only. --}}
                                <td class="lm-num">{{ $row['provider'] === $all ? max(0, $row['viewed'] - $row['started']) : '—' }}</td>
                                <td class="lm-num">{{ max(0, $row['started'] - $row['paid']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($report['outside'] !== [])
                <h3 class="lm-text" style="margin-top: 1.25rem; font-weight: 600;">{{ __('admin.widgets.billing_funnel.outside.heading') }}</h3>
                <p class="lm-muted">{{ __('admin.widgets.billing_funnel.outside.help') }}</p>
                <div class="lm-scroll">
                    <table class="lm-table" data-testid="billing-funnel-outside">
                        <thead>
                            <tr>
                                <th>{{ __('admin.widgets.billing_funnel.columns.provider') }}</th>
                                <th>{{ __('admin.widgets.billing_funnel.columns.locale') }}</th>
                                <th class="lm-num">{{ __('admin.widgets.billing_funnel.columns.started') }}</th>
                                <th class="lm-num">{{ __('admin.widgets.billing_funnel.columns.paid') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report['outside'] as $row)
                                <tr wire:key="bfo-{{ $row['provider'] }}-{{ $row['locale'] }}">
                                    <td class="lm-nowrap">{{ $label('providers', $row['provider']) }}</td>
                                    <td class="lm-nowrap">{{ $label('locales', $row['locale']) }}</td>
                                    <td class="lm-num">{{ $row['started'] }}</td>
                                    <td class="lm-num">{{ $row['paid'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($report['placements'] !== [])
                <h3 class="lm-text" style="margin-top: 1.25rem; font-weight: 600;">{{ __('admin.widgets.billing_funnel.placements_heading') }}</h3>
                <div class="lm-scroll">
                    <table class="lm-table" data-testid="billing-funnel-placements">
                        <thead>
                            <tr>
                                <th>{{ __('admin.widgets.billing_funnel.columns.placement') }}</th>
                                <th class="lm-num">{{ __('admin.widgets.billing_funnel.columns.learners') }}</th>
                                <th class="lm-num">{{ __('admin.widgets.billing_funnel.columns.views') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report['placements'] as $row)
                                <tr wire:key="bfp-{{ $row['placement'] }}">
                                    <td>{{ $label('placements', $row['placement']) }}</td>
                                    <td class="lm-num">{{ $row['learners'] }}</td>
                                    <td class="lm-num">{{ $row['views'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($report['orders'] !== [])
                <h3 class="lm-text" style="margin-top: 1.25rem; font-weight: 600;">{{ __('admin.widgets.billing_funnel.orders_heading') }}</h3>
                <div class="lm-scroll">
                    <table class="lm-table" data-testid="billing-funnel-orders">
                        <thead>
                            <tr>
                                <th>{{ __('admin.widgets.billing_funnel.columns.provider') }}</th>
                                <th>{{ __('admin.widgets.billing_funnel.columns.locale') }}</th>
                                <th>{{ __('admin.widgets.billing_funnel.columns.status') }}</th>
                                <th>{{ __('admin.widgets.billing_funnel.columns.reason') }}</th>
                                <th class="lm-num">{{ __('admin.widgets.billing_funnel.columns.orders') }}</th>
                                <th class="lm-num">{{ __('admin.widgets.billing_funnel.columns.learners') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report['orders'] as $row)
                                <tr wire:key="bfr-{{ $loop->index }}">
                                    <td class="lm-nowrap">{{ $label('providers', $row['provider']) }}</td>
                                    <td class="lm-nowrap">{{ $label('locales', $row['locale']) }}</td>
                                    <td class="lm-nowrap">{{ $label('statuses', $row['status']) }}</td>
                                    <td>{{ $row['reason'] === null ? '—' : $label('reasons', $row['reason']) }}</td>
                                    <td class="lm-num">{{ $row['orders'] }}</td>
                                    <td class="lm-num">{{ $row['learners'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif

        <x-filament::section :heading="__('admin.widgets.billing_funnel.help.heading')" collapsible collapsed compact style="margin-top: 1.25rem;">
            <ul class="lm-muted" style="display: flex; flex-direction: column; gap: 0.375rem; margin: 0; padding-inline-start: 1.1rem; list-style: disc;">
                @foreach (['viewed', 'started', 'paid', 'studied', 'lost'] as $key)
                    <li>{{ __('admin.widgets.billing_funnel.help.'.$key) }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    </x-filament::section>
</x-filament-widgets::widget>
