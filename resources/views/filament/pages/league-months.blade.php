@php
    use App\Filament\Pages\LeagueMonthReview;
    use App\Filament\Resources\Support\AdminDates;
    use App\Filament\Resources\Support\AdminLabels;
    use App\Filament\Resources\Support\LeagueMonthDisplay;

    $months = $this->months();
@endphp

<x-filament-panels::page>
    @include('filament.pages.partials.league-month-styles')

    @if ($months->isEmpty())
        <x-filament::section>{{ __('admin.league_month.empty') }}</x-filament::section>
    @else
        <x-filament::section>
            <div class="lm-scroll">
                <table class="lm-table">
                    <thead>
                        <tr>
                            <th>{{ __('admin.league_month.columns.month') }}</th>
                            <th>{{ __('admin.league_month.columns.status') }}</th>
                            <th class="lm-num">{{ __('admin.league_month.columns.participants') }}</th>
                            <th class="lm-num">{{ __('admin.league_month.columns.candidates') }}</th>
                            <th>{{ __('admin.league_month.columns.closed_at') }}</th>
                            <th>{{ __('admin.league_month.columns.confirmed') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($months as $month)
                            <tr wire:key="month-{{ $month->id }}">
                                <td class="lm-nowrap"><strong>{{ LeagueMonthDisplay::month($month) }}</strong></td>
                                <td class="lm-nowrap">
                                    <x-filament::badge :color="LeagueMonthDisplay::statusColor($month->status)">
                                        {{ AdminLabels::value('league_month.status', $month->status) }}
                                    </x-filament::badge>
                                </td>
                                <td class="lm-num">{{ $month->participants_count }}</td>
                                <td class="lm-num">{{ $month->getAttribute('candidates_count') }}</td>
                                <td class="lm-nowrap">{{ $month->closed_at ? AdminDates::dateTime($month->closed_at) : '—' }}</td>
                                <td>
                                    @if ($month->confirmed_at)
                                        {{ __('admin.league_month.info.confirmed_by', ['who' => $month->confirmedBy?->name ?? '—', 'when' => AdminDates::dateTime($month->confirmed_at)]) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="lm-nowrap" style="text-align: end;">
                                    <x-filament::link :href="LeagueMonthReview::getUrl(['month' => $month->id])" icon="heroicon-o-arrow-right">
                                        {{ __('admin.league_month.open') }}
                                    </x-filament::link>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
