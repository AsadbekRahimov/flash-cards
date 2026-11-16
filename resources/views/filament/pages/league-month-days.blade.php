@if ($days === [])
    <p class="text-sm">{{ __('admin.league_month.days.empty') }}</p>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-gray-200 dark:border-white/10">
                    <th class="py-1 pe-3 font-semibold">{{ __('admin.league_month.days.date') }}</th>
                    <th class="py-1 pe-3 font-semibold">{{ __('admin.league_month.days.reviews') }}</th>
                    <th class="py-1 pe-3 font-semibold">{{ __('admin.league_month.days.correct') }}</th>
                    <th class="py-1 pe-3 font-semibold">{{ __('admin.league_month.days.new_words') }}</th>
                    <th class="py-1 pe-3 font-semibold">{{ __('admin.league_month.days.goal') }}</th>
                    <th class="py-1 pe-3 font-semibold">{{ __('admin.league_month.days.points') }}</th>
                    <th class="py-1 font-semibold" title="{{ __('admin.league_month.days.counted_hint', ['cap' => $cap > 0 ? $cap : '—']) }}">{{ __('admin.league_month.days.counted') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($days as $day)
                    <tr class="border-b border-gray-100 dark:border-white/5">
                        <td class="py-1 pe-3">{{ \App\Filament\Resources\Support\AdminDates::date(\Carbon\CarbonImmutable::parse($day['date'])) }}</td>
                        <td class="py-1 pe-3">{{ $day['reviews'] }}</td>
                        <td class="py-1 pe-3">{{ $day['correct'] }}</td>
                        <td class="py-1 pe-3">{{ $day['new_words'] }}</td>
                        <td class="py-1 pe-3">{{ $day['goal_met'] ? __('admin.common.yes') : __('admin.common.no') }}</td>
                        <td class="py-1 pe-3">{{ $day['points'] }}</td>
                        <td class="py-1 font-medium {{ $day['counted'] < $day['points'] ? 'text-warning-600 dark:text-warning-400' : '' }}">{{ $day['counted'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
