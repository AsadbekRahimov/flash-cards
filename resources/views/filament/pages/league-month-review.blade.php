@php
    use App\Filament\Resources\LearnerResource;
    use App\Filament\Resources\Support\AdminDates;
    use App\Filament\Resources\Support\AdminLabels;
    use App\Filament\Resources\Support\LeagueMonthDisplay;

    use App\Domain\Engagement\Enums\ScoreCategory;

    $review = $this->review();
    $month = $this->month;
    $confirmed = $month->status === 'confirmed';
    $pending = $month->status === 'pending_review';
    // A v2 month (docs/32 §7): two tables, the one on the screen picked by the tabs; a v1 month: one table, as before.
    $v2 = $this->isV2();
    $category = $this->category();
    $rows = $this->rows();
    $places = $this->placesOf($category);
    $prizeByPlace = [];
    foreach ($this->prizesOf($category) as $prize) {
        $prizeByPlace[$prize->place] = $prize;
    }
    $prizeTables = $v2
        ? array_map(fn (ScoreCategory $c): array => ['category' => $c, 'prizes' => $this->prizesOf($c)], ScoreCategory::cases())
        : [['category' => null, 'prizes' => $review->prizes]];
    $overflow = $this->tieOverflow();
    $counts = $v2 ? $this->categoryCounts() : [];
    // A place several candidates share (a tie of a v2 month): marked on each of them.
    $shared = array_count_values(array_filter(array_map(static fn ($row): ?int => $row->provisionalPlace, $rows), static fn (?int $place): bool => $place !== null));
@endphp

<x-filament-panels::page>
    @include('filament.pages.partials.league-month-styles')

    <x-filament::section>
        <dl class="lm-head">
            <div>
                <dt>{{ __('admin.league_month.columns.status') }}</dt>
                <dd>
                    <x-filament::badge :color="LeagueMonthDisplay::statusColor($month->status)">
                        {{ AdminLabels::value('league_month.status', $month->status) }}
                    </x-filament::badge>
                </dd>
            </div>
            <div>
                <dt>{{ __('admin.league_month.info.dates') }}</dt>
                <dd>{{ AdminDates::date($month->starts_on) }} — {{ AdminDates::date($month->ends_on) }}</dd>
            </div>
            <div>
                <dt>{{ __('admin.league_month.info.participants') }}</dt>
                <dd>{{ $month->participants_count }}</dd>
            </div>
            <div>
                <dt>{{ __('admin.league_month.columns.rules') }}</dt>
                <dd>{{ __('admin.league_month.rules.short', ['version' => $month->rules_version]) }}</dd>
            </div>
            <div>
                <dt>{{ $confirmed ? __('admin.league_month.info.confirmed') : __('admin.league_month.info.closed_at') }}</dt>
                <dd>
                    @if ($confirmed && $month->confirmed_at)
                        {{ AdminDates::dateTime($month->confirmed_at) }}
                    @elseif ($month->closed_at)
                        {{ AdminDates::dateTime($month->closed_at) }}
                    @else
                        {{ __('admin.league_month.info.not_closed') }}
                    @endif
                </dd>
            </div>
        </dl>
    </x-filament::section>

    <x-filament::section :heading="__('admin.league_month.prizes.heading')">
        @foreach ($prizeTables as $table)
            @if ($table['category'] !== null)
                <p class="lm-text" style="margin-top: 0.5rem;"><strong>{{ __('admin.league_month.prizes.category', ['category' => AdminLabels::value('learners.score_days.category', $table['category']->value), 'places' => $this->placesOf($table['category'])]) }}</strong></p>
            @endif
            @if ($table['prizes'] === [])
                <p class="lm-text">{{ __('admin.league_month.prizes.none') }}</p>
            @else
                <ul class="lm-list">
                    @foreach ($table['prizes'] as $prize)
                        <li wire:key="prize-{{ $table['category']?->value ?? 'all' }}-{{ $prize->place }}">
                            <strong>{{ __('admin.league_month.prizes.place', ['place' => $prize->place]) }}:</strong>
                            RU — {{ $prize->ru !== '' ? $prize->ru : __('admin.league_month.prizes.no_title') }};
                            UZ — {{ $prize->uz !== '' ? $prize->uz : __('admin.league_month.prizes.no_title') }}
                        </li>
                    @endforeach
                </ul>
            @endif
        @endforeach
        <p class="lm-note">
            @if ($confirmed)
                {{ __('admin.league_month.prizes.locked') }}
            @elseif ($month->prizes === null)
                {{ __('admin.league_month.prizes.default') }}
            @else
                {{ __('admin.league_month.prizes.own') }}
            @endif
        </p>
    </x-filament::section>

    <x-filament::section :heading="__('admin.league_month.candidates.heading')">
        @if ($v2)
            <x-filament::tabs style="margin-bottom: 0.75rem;">
                @foreach (ScoreCategory::cases() as $tab)
                    <x-filament::tabs.item :active="$category === $tab" :badge="$counts[$tab->value]" wire:click="$set('category', '{{ $tab->value }}')" wire:key="tab-{{ $tab->value }}">
                        {{ AdminLabels::value('learners.score_days.category', $tab->value) }}
                    </x-filament::tabs.item>
                @endforeach
            </x-filament::tabs>
        @endif

        @if ($overflow !== [])
            <div class="lm-confirm" style="margin-bottom: 0.75rem;">
                <p class="lm-alert">{{ __('admin.league_month.candidates.overflow', ['tie' => implode('; ', array_map(fn (string $c, array $t): string => __('admin.league_month.confirm.overflow_line', ['category' => AdminLabels::value('learners.score_days.category', $c), 'winners' => $t['winners'], 'places' => $t['places'], 'allowed' => $t['allowed']]), array_keys($overflow), $overflow))]) }}</p>
            </div>
        @endif

        @if ($rows === [])
            <p class="lm-text">{{ __('admin.league_month.candidates.empty') }}</p>
        @else
            @unless ($confirmed)
                <p class="lm-note" style="margin-top: 0; margin-bottom: 0.75rem;">
                    {{ $v2
                        ? __('admin.league_month.candidates.hint_v2', ['places' => $places, 'reserve' => $this->tieReserveOf($category)])
                        : __('admin.league_month.candidates.hint', ['places' => $places]) }}
                </p>
            @endunless

            <div class="lm-scroll">
                <table class="lm-table">
                    <thead>
                        <tr>
                            <th>{{ __('admin.league_month.columns.place') }}</th>
                            <th>{{ __('admin.league_month.columns.learner') }}</th>
                            <th class="lm-num">{{ __('admin.league_month.columns.points') }}</th>
                            @if ($v2)
                                <th class="lm-num">{{ __('admin.league_month.columns.long_words') }}</th>
                            @endif
                            <th class="lm-num">{{ __('admin.league_month.columns.active_days') }}</th>
                            <th>{{ __('admin.league_month.columns.signals') }}</th>
                            <th>{{ __('admin.league_month.columns.candidate_status') }}</th>
                            <th>{{ __('admin.league_month.columns.prize') }}</th>
                            <th>{{ __('admin.league_month.columns.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $candidate = $row->candidate;
                                $learner = $row->learner;
                                $unreachable = LeagueMonthDisplay::unreachable($learner);
                                // A deleted account keeps no name on the page (it is going to be purged); the others show it.
                                $name = $unreachable === 'deleted'
                                    ? ($learner ? '#'.$learner->id : __('admin.league_month.candidates.deleted'))
                                    : ($learner?->first_name ?: '#'.$learner?->id);
                                $signals = is_array($candidate->signals) ? $candidate->signals : [];
                                $username = LeagueMonthDisplay::telegramUsername($learner?->username);
                            @endphp
                            <tr wire:key="candidate-{{ $candidate->id }}" >
                                <td class="lm-nowrap">
                                    <strong>{{ $row->provisionalPlace ?? '—' }}</strong>
                                    <div class="lm-muted">#{{ $candidate->rank }}</div>
                                    @if ($row->provisionalPlace !== null && ($shared[$row->provisionalPlace] ?? 0) > 1)
                                        <div class="lm-muted">{{ __('admin.league_month.candidates.shared_place') }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if ($unreachable !== null)
                                        <div style="margin-bottom: 0.25rem;"><x-filament::badge color="danger">{{ __('admin.league_month.candidates.state.'.$unreachable) }}</x-filament::badge></div>
                                    @endif
                                    @if ($learner && $unreachable !== 'deleted')
                                        <x-filament::link :href="LearnerResource::getUrl('view', ['record' => $learner->id])">{{ $name }}</x-filament::link>
                                        {{-- The way to reach a winner who is to be given a prize by hand: for the administrator only. --}}
                                        @if ($row->provisionalPlace !== null)
                                            <div class="lm-lines">
                                                @if ($username)
                                                    <div><a style="text-decoration: underline;" href="https://t.me/{{ $username }}" target="_blank" rel="noopener noreferrer">{{ '@'.$username }}</a></div>
                                                @elseif ($learner->username)
                                                    <div>{{ '@'.ltrim($learner->username, '@') }}</div>
                                                @endif
                                                <div>
                                                    {{ __('admin.league_month.candidates.telegram_id') }}:
                                                    <a style="text-decoration: underline;" href="tg://user?id={{ $learner->telegram_user_id }}">{{ $learner->telegram_user_id }}</a>
                                                </div>
                                            </div>
                                        @endif
                                    @else
                                        {{ $name }}
                                    @endif
                                </td>
                                <td class="lm-num">{{ $candidate->points }}</td>
                                @if ($v2)
                                    <td class="lm-num">{{ is_numeric($signals['long_words'] ?? null) ? (int) $signals['long_words'] : '—' }}</td>
                                @endif
                                <td class="lm-num">{{ $candidate->active_days }}</td>
                                <td class="lm-signals">
                                    <div class="lm-flags">
                                        @forelse (LeagueMonthDisplay::flags($signals) as $flag)
                                            <x-filament::badge :color="$flag['color']" :tooltip="$flag['hint']">{{ $flag['label'] }}</x-filament::badge>
                                        @empty
                                            <span class="lm-muted">{{ __('admin.league_month.signals.none') }}</span>
                                        @endforelse
                                    </div>
                                    <ul class="lm-facts">
                                        @foreach (LeagueMonthDisplay::facts($signals) as $fact)
                                            <li>{{ $fact }}</li>
                                        @endforeach
                                    </ul>
                                </td>
                                <td class="lm-nowrap">
                                    <x-filament::badge :color="$candidate->isDisqualified() ? 'danger' : ($candidate->isWinner() ? 'success' : 'gray')">
                                        {{ AdminLabels::value('league_month.candidate_status', $candidate->status) }}
                                    </x-filament::badge>
                                    @if ($candidate->isDisqualified() && $candidate->disqualify_reason)
                                        <div class="lm-reason" style="white-space: normal;">{{ $candidate->disqualify_reason }}</div>
                                    @endif
                                </td>
                                <td class="lm-small">
                                    @if ($candidate->isWinner())
                                        @php($prize = $prizeByPlace[$candidate->place] ?? null)
                                        @if ($prize)<div><strong>{{ $prize->title('ru') }}</strong></div>@endif
                                        {{ $candidate->prize_delivered_at ? __('admin.league_month.candidates.delivered', ['when' => AdminDates::dateTime($candidate->prize_delivered_at)]) : __('admin.league_month.candidates.not_delivered') }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    <div class="lm-actions">
                                        @if ($learner)
                                            {{ ($this->daysAction)(['learner' => $candidate->learner_id, 'name' => $name]) }}
                                        @endif
                                        @if ($pending && ! $candidate->isDisqualified())
                                            {{ ($this->disqualifyAction)(['candidate' => $candidate->id, 'name' => $name]) }}
                                        @endif
                                        @if ($pending && $candidate->isDisqualified())
                                            {{ ($this->reinstateAction)(['candidate' => $candidate->id, 'name' => $name]) }}
                                        @endif
                                        @if ($confirmed && $candidate->isWinner() && ! $candidate->prize_delivered_at)
                                            {{ ($this->prizeDeliveredAction)(['candidate' => $candidate->id, 'name' => $name]) }}
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
