@php
    use App\Filament\Resources\LearnerResource;
    use App\Filament\Resources\Support\AdminDates;
    use App\Filament\Resources\Support\AdminLabels;
    use App\Filament\Resources\Support\LeagueMonthDisplay;

    $review = $this->review();
    $month = $this->month;
    $confirmed = $month->status === 'confirmed';
    $pending = $month->status === 'pending_review';
    $places = (int) config('leagues.monthly.places', 3);
    $prizeByPlace = [];
    foreach ($review->prizes as $prize) {
        $prizeByPlace[$prize->place] = $prize;
    }
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('admin.league_month.columns.status') }}</dt>
                <dd>
                    <x-filament::badge :color="LeagueMonthDisplay::statusColor($month->status)">
                        {{ AdminLabels::value('league_month.status', $month->status) }}
                    </x-filament::badge>
                </dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('admin.league_month.info.dates') }}</dt>
                <dd>{{ AdminDates::date($month->starts_on) }} — {{ AdminDates::date($month->ends_on) }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('admin.league_month.info.participants') }}</dt>
                <dd>{{ $month->participants_count }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ $confirmed ? __('admin.league_month.info.confirmed') : __('admin.league_month.info.closed_at') }}</dt>
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
        @if ($review->prizes === [])
            <p class="text-sm">{{ __('admin.league_month.prizes.none') }}</p>
        @else
            <ul class="space-y-1 text-sm">
                @foreach ($review->prizes as $prize)
                    <li wire:key="prize-{{ $prize->place }}">
                        <span class="font-medium">{{ __('admin.league_month.prizes.place', ['place' => $prize->place]) }}:</span>
                        RU — {{ $prize->ru !== '' ? $prize->ru : __('admin.league_month.prizes.no_title') }};
                        UZ — {{ $prize->uz !== '' ? $prize->uz : __('admin.league_month.prizes.no_title') }}
                    </li>
                @endforeach
            </ul>
        @endif
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
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
        @if ($review->candidates === [])
            <p class="text-sm">{{ __('admin.league_month.candidates.empty') }}</p>
        @else
            @unless ($confirmed)
                <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">{{ __('admin.league_month.candidates.hint', ['places' => $places]) }}</p>
            @endunless

            <div>
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pe-3 font-semibold">{{ __('admin.league_month.columns.place') }}</th>
                            <th class="py-2 pe-3 font-semibold">{{ __('admin.league_month.columns.learner') }}</th>
                            <th class="py-2 pe-3 font-semibold">{{ __('admin.league_month.columns.points') }}</th>
                            <th class="py-2 pe-3 font-semibold">{{ __('admin.league_month.columns.active_days') }}</th>
                            <th class="py-2 pe-3 font-semibold">{{ __('admin.league_month.columns.signals') }}</th>
                            <th class="py-2 pe-3 font-semibold">{{ __('admin.league_month.columns.candidate_status') }}</th>
                            <th class="py-2 pe-3 font-semibold">{{ __('admin.league_month.columns.prize') }}</th>
                            <th class="py-2 font-semibold">{{ __('admin.league_month.columns.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($review->candidates as $row)
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
                            <tr wire:key="candidate-{{ $candidate->id }}" class="border-b border-gray-100 align-top dark:border-white/5">
                                <td class="py-2 pe-3 font-semibold">
                                    {{ $row->provisionalPlace ?? '—' }}
                                    <div class="text-xs font-normal text-gray-500 dark:text-gray-400">#{{ $candidate->rank }}</div>
                                </td>
                                <td class="py-2 pe-3">
                                    @if ($unreachable !== null)
                                        <x-filament::badge class="mb-1 w-fit whitespace-nowrap" color="danger">{{ __('admin.league_month.candidates.state.'.$unreachable) }}</x-filament::badge>
                                    @endif
                                    @if ($learner && $unreachable !== 'deleted')
                                        <x-filament::link :href="LearnerResource::getUrl('view', ['record' => $learner->id])">{{ $name }}</x-filament::link>
                                        {{-- The way to reach a winner who is to be given a prize by hand: for the administrator only. --}}
                                        @if ($row->provisionalPlace !== null)
                                            <div class="mt-1 space-y-0.5 text-xs">
                                                @if ($username)
                                                    <div><a class="text-primary-600 underline dark:text-primary-400" href="https://t.me/{{ $username }}" target="_blank" rel="noopener noreferrer">{{ '@'.$username }}</a></div>
                                                @elseif ($learner->username)
                                                    <div>{{ '@'.ltrim($learner->username, '@') }}</div>
                                                @endif
                                                <div>
                                                    {{ __('admin.league_month.candidates.telegram_id') }}:
                                                    <a class="text-primary-600 underline dark:text-primary-400" href="tg://user?id={{ $learner->telegram_user_id }}">{{ $learner->telegram_user_id }}</a>
                                                </div>
                                            </div>
                                        @endif
                                    @else
                                        {{ $name }}
                                    @endif
                                </td>
                                <td class="py-2 pe-3">{{ $candidate->points }}</td>
                                <td class="py-2 pe-3">{{ $candidate->active_days }}</td>
                                <td class="py-2 pe-3">
                                    <div class="flex flex-wrap gap-1">
                                        @forelse (LeagueMonthDisplay::flags($signals) as $flag)
                                            <x-filament::badge :color="$flag['color']" :tooltip="$flag['hint']">{{ $flag['label'] }}</x-filament::badge>
                                        @empty
                                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin.league_month.signals.none') }}</span>
                                        @endforelse
                                    </div>
                                    <ul class="mt-1 grid max-w-md grid-cols-2 gap-x-4 gap-y-0.5 text-xs text-gray-600 dark:text-gray-400">
                                        @foreach (LeagueMonthDisplay::facts($signals) as $fact)
                                            <li>{{ $fact }}</li>
                                        @endforeach
                                    </ul>
                                </td>
                                <td class="whitespace-nowrap py-2 pe-3">
                                    <x-filament::badge :color="$candidate->isDisqualified() ? 'danger' : ($candidate->isWinner() ? 'success' : 'gray')">
                                        {{ AdminLabels::value('league_month.candidate_status', $candidate->status) }}
                                    </x-filament::badge>
                                    @if ($candidate->isDisqualified() && $candidate->disqualify_reason)
                                        <div class="mt-1 max-w-xs text-xs">{{ $candidate->disqualify_reason }}</div>
                                    @endif
                                </td>
                                <td class="py-2 pe-3 text-xs">
                                    @if ($candidate->isWinner())
                                        @php($prize = $prizeByPlace[$candidate->place] ?? null)
                                        @if ($prize)<div class="font-medium">{{ $prize->title('ru') }}</div>@endif
                                        {{ $candidate->prize_delivered_at ? __('admin.league_month.candidates.delivered', ['when' => AdminDates::dateTime($candidate->prize_delivered_at)]) : __('admin.league_month.candidates.not_delivered') }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="py-2">
                                    <div class="flex flex-wrap gap-1">
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
