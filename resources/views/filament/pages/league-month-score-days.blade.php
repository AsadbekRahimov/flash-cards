@php
    use App\Filament\Pages\LearnerScoreDayCredits;
    use App\Filament\Resources\Support\AdminDates;
    use App\Filament\Resources\Support\AdminLabels;
    use Carbon\CarbonImmutable;
@endphp

@include('filament.pages.partials.league-month-styles')

{{-- The rating days of a v2 month (docs/32 §2.4): the monthly count as stored, the raw facts, the way to the journal of the day. --}}
@if ($days === [])
    <p class="lm-text">{{ __('admin.league_month.days.empty') }}</p>
@else
    <div class="lm-scroll lm-days">
        <table class="lm-table">
            <thead>
                <tr>
                    <th>{{ __('admin.league_month.days.rating_day') }}</th>
                    <th>{{ __('admin.league_month.days.category') }}</th>
                    <th class="lm-num">{{ __('admin.league_month.days.month_points') }}</th>
                    <th class="lm-num">{{ __('admin.league_month.days.month_new') }}</th>
                    <th class="lm-num">{{ __('admin.league_month.days.month_review') }}</th>
                    <th class="lm-num">{{ __('admin.league_month.days.month_long') }}</th>
                    <th>{{ __('admin.league_month.days.capped') }}</th>
                    <th class="lm-num">{{ __('admin.league_month.days.raw_points') }}</th>
                    <th class="lm-num">{{ __('admin.league_month.days.revision') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($days as $day)
                    <tr>
                        <td class="lm-nowrap">{{ AdminDates::date(CarbonImmutable::parse($day['rating_day'])) }}</td>
                        <td>{{ AdminLabels::value('learners.score_days.category', $day['category']) }}</td>
                        <td class="lm-num"><strong>{{ $day['month_points'] }}</strong></td>
                        <td class="lm-num">{{ $day['month_new'] }}</td>
                        <td class="lm-num">{{ $day['month_review'] }}</td>
                        <td class="lm-num">{{ $day['month_long'] }}</td>
                        <td class="{{ $day['capped'] ? 'lm-warn' : '' }}">{{ $day['capped'] ? __('admin.common.yes') : __('admin.common.no') }}</td>
                        <td class="lm-num">{{ $day['raw_points'] }}</td>
                        <td class="lm-num">{{ $day['revision'] > 0 ? $day['revision'] : '—' }}</td>
                        <td class="lm-nowrap">
                            <x-filament::link :href="LearnerScoreDayCredits::getUrl(['learner' => $learnerId, 'day' => $day['rating_day']])" icon="heroicon-o-list-bullet">
                                {{ __('admin.league_month.days.journal') }}
                            </x-filament::link>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
