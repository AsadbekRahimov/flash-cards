@php
    use App\Filament\Pages\LeagueMonthReview;
    use App\Filament\Resources\Support\AdminDates;
    use App\Filament\Resources\Support\AdminLabels;
    use App\Filament\Resources\Support\LeagueMonthDisplay;

    $months = $this->months();
    $rules = $this->scoringRules();
    $period = fn (array $p): string => __($p['stored'] ? 'admin.league_month.rules.period' : 'admin.league_month.rules.period_new', [
        'from' => AdminDates::date(\Carbon\CarbonImmutable::parse($p['starts_on'])),
        'version' => $p['version'],
    ]);
@endphp

<x-filament-panels::page>
    @include('filament.pages.partials.league-month-styles')

    {{-- The word-points rules (docs/32 §6.1, §8.5): read only; a change is a new version in the code, never an edit here. --}}
    <x-filament::section :heading="__('admin.league_month.rules.heading')" collapsible>
        <dl class="lm-head">
            <div>
                <dt>{{ __('admin.league_month.rules.mode') }}</dt>
                <dd>
                    <x-filament::badge :color="$rules['mode'] === 'live' ? 'success' : ($rules['mode'] === 'shadow' ? 'warning' : 'gray')">{{ AdminLabels::value('league_month.rules.modes', $rules['mode']) }}</x-filament::badge>
                </dd>
            </div>
            <div>
                <dt>{{ __('admin.league_month.rules.starts_on') }}</dt>
                <dd>{{ $rules['starts_on'] ? AdminDates::date(\Carbon\CarbonImmutable::parse($rules['starts_on'])) : __('admin.league_month.rules.not_scheduled') }}</dd>
            </div>
            <div>
                <dt>{{ __('admin.league_month.rules.week') }}</dt>
                <dd>{{ $period($rules['week']) }}</dd>
            </div>
            <div>
                <dt>{{ __('admin.league_month.rules.month') }}</dt>
                <dd>{{ $period($rules['month']) }}</dd>
            </div>
            <div>
                <dt>{{ __('admin.league_month.rules.game') }}</dt>
                <dd>{{ $rules['game_enabled'] ? __('admin.league_month.rules.game_on') : __('admin.league_month.rules.game_off') }}</dd>
            </div>
            <div>
                <dt>{{ __('admin.league_month.rules.retention') }}</dt>
                <dd>{{ __('admin.league_month.rules.retention_days', ['days' => $rules['retention_days']]) }}</dd>
            </div>
        </dl>

        @foreach ($rules['versions'] as $version)
            <div class="lm-scroll" style="margin-top: 1rem;" wire:key="rules-v{{ $version['version'] }}">
                <p class="lm-text"><strong>{{ __('admin.league_month.rules.version', ['version' => $version['version']]) }}</strong>
                    {{ __('admin.league_month.rules.version_facts', [
                        'new' => $version['points']['new'],
                        'review' => $version['points']['review'],
                        'long' => $version['points']['long'],
                        'interval' => $version['long_interval_days'],
                        'best' => $version['best_days'],
                    ]) }}
                </p>
                <table class="lm-table">
                    <thead>
                        <tr>
                            <th>{{ __('admin.league_month.rules.category') }}</th>
                            <th class="lm-num">{{ __('admin.league_month.rules.limit_new') }}</th>
                            <th class="lm-num">{{ __('admin.league_month.rules.limit_review') }}</th>
                            <th class="lm-num">{{ __('admin.league_month.rules.day_cap') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($version['limits'] as $category => $limit)
                            <tr>
                                <td>{{ AdminLabels::value('learners.score_days.category', $category) }}</td>
                                <td class="lm-num">{{ $limit['new'] }}</td>
                                <td class="lm-num">{{ $limit['review'] }}</td>
                                <td class="lm-num">{{ $limit['day_cap'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
        <p class="lm-note">{{ __('admin.league_month.rules.note') }}</p>
    </x-filament::section>

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
                            <th>{{ __('admin.league_month.columns.rules') }}</th>
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
                                <td class="lm-nowrap">{{ __('admin.league_month.rules.short', ['version' => $month->rules_version]) }}</td>
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
