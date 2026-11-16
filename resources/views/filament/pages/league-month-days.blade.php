@include('filament.pages.partials.league-month-styles')

@if ($days === [])
    <p class="lm-text">{{ __('admin.league_month.days.empty') }}</p>
@else
    <div class="lm-scroll lm-days">
        <table class="lm-table">
            <thead>
                <tr>
                    <th>{{ __('admin.league_month.days.date') }}</th>
                    <th class="lm-num">{{ __('admin.league_month.days.reviews') }}</th>
                    <th class="lm-num">{{ __('admin.league_month.days.correct') }}</th>
                    <th class="lm-num">{{ __('admin.league_month.days.new_words') }}</th>
                    <th>{{ __('admin.league_month.days.goal') }}</th>
                    <th class="lm-num">{{ __('admin.league_month.days.points') }}</th>
                    <th class="lm-num" title="{{ __('admin.league_month.days.counted_hint', ['cap' => $cap > 0 ? $cap : '—']) }}">{{ __('admin.league_month.days.counted') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($days as $day)
                    <tr>
                        <td class="lm-nowrap">{{ \App\Filament\Resources\Support\AdminDates::date(\Carbon\CarbonImmutable::parse($day['date'])) }}</td>
                        <td class="lm-num">{{ $day['reviews'] }}</td>
                        <td class="lm-num">{{ $day['correct'] }}</td>
                        <td class="lm-num">{{ $day['new_words'] }}</td>
                        <td>{{ $day['goal_met'] ? __('admin.common.yes') : __('admin.common.no') }}</td>
                        <td class="lm-num">{{ $day['points'] }}</td>
                        <td class="lm-num {{ $day['counted'] < $day['points'] ? 'lm-warn' : '' }}"><strong>{{ $day['counted'] }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
