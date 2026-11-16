@php
    use App\Filament\Pages\LeagueMonthReview;
    use App\Filament\Resources\Support\AdminDates;
    use App\Filament\Resources\Support\AdminLabels;
    use App\Filament\Resources\Support\LeagueMonthDisplay;

    $months = $this->months();
@endphp

<x-filament-panels::page>
    @if ($months->isEmpty())
        <x-filament::section>{{ __('admin.league_month.empty') }}</x-filament::section>
    @else
        <x-filament::section>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pe-4 font-semibold">{{ __('admin.league_month.columns.month') }}</th>
                            <th class="py-2 pe-4 font-semibold">{{ __('admin.league_month.columns.status') }}</th>
                            <th class="py-2 pe-4 font-semibold">{{ __('admin.league_month.columns.participants') }}</th>
                            <th class="py-2 pe-4 font-semibold">{{ __('admin.league_month.columns.candidates') }}</th>
                            <th class="py-2 pe-4 font-semibold">{{ __('admin.league_month.columns.closed_at') }}</th>
                            <th class="py-2 pe-4 font-semibold">{{ __('admin.league_month.columns.confirmed') }}</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($months as $month)
                            <tr wire:key="month-{{ $month->id }}" class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pe-4 font-medium">{{ LeagueMonthDisplay::month($month) }}</td>
                                <td class="py-2 pe-4">
                                    <x-filament::badge :color="LeagueMonthDisplay::statusColor($month->status)">
                                        {{ AdminLabels::value('league_month.status', $month->status) }}
                                    </x-filament::badge>
                                </td>
                                <td class="py-2 pe-4">{{ $month->participants_count }}</td>
                                <td class="py-2 pe-4">{{ $month->getAttribute('candidates_count') }}</td>
                                <td class="py-2 pe-4">{{ $month->closed_at ? AdminDates::dateTime($month->closed_at) : '—' }}</td>
                                <td class="py-2 pe-4">
                                    @if ($month->confirmed_at)
                                        {{ __('admin.league_month.info.confirmed_by', ['who' => $month->confirmedBy?->name ?? '—', 'when' => AdminDates::dateTime($month->confirmed_at)]) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="py-2 text-end">
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
