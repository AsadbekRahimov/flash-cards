@php
    use App\Domain\Learners\Support\Regions;
    use App\Filament\Resources\Support\AdminDates;
    use App\Filament\Resources\Support\AdminLabels;
    use App\Filament\Resources\Support\AdminNumbers;
    use Carbon\CarbonImmutable;

    $poll = $this->poll();
    $summary = $this->summary();
    $breakdowns = $this->breakdowns();
    $statuses = $summary['statuses'];
    $sent = $statuses['sent'] ?? 0;
    $share = fn (int $part, int $whole): string => $whole > 0 ? AdminNumbers::decimal($part * 100 / $whole, 1, true).' %' : '—';
    $groupLabel = function (string $dimension, array $group): string {
        return match ($dimension) {
            'locale' => AdminLabels::value('polls.locale', $group['key']),
            'plan' => AdminLabels::value('polls.plan', $group['key']),
            'region' => $group['key'] === 'none' ? __('admin.polls.results.none') : Regions::name($group['key'], 'ru'),
            'course' => $group['key'] === 'none' ? __('admin.polls.results.none') : $group['label'],
            default => $group['label'],
        };
    };
@endphp

<x-filament-panels::page>
    {{--
        The panel has no theme build of its own: Tailwind utility classes in our templates produce no CSS. These few rules are
        prefixed `pl-`, use `currentColor` and translucent greys (they follow the dark theme) and need no build.
    --}}
    @once
        <style>
            .pl-head { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: 1rem 1.5rem; margin: 0; }
            .pl-head > div { display: flex; flex-direction: column; gap: 0.25rem; min-width: 0; }
            .pl-head dt { font-size: 0.8125rem; opacity: 0.65; }
            .pl-head dd { margin: 0; font-size: 1.125rem; font-variant-numeric: tabular-nums; }
            .pl-scroll { overflow-x: auto; }
            .pl-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; line-height: 1.25rem; }
            .pl-table th, .pl-table td { padding: 0.5rem; text-align: start; vertical-align: top; }
            .pl-table thead th { font-weight: 600; border-bottom: 1px solid rgba(128, 128, 128, 0.35); }
            .pl-table tbody td { border-top: 1px solid rgba(128, 128, 128, 0.2); }
            .pl-table .pl-num { text-align: end; font-variant-numeric: tabular-nums; white-space: nowrap; }
            .pl-note { margin-top: 0.75rem; font-size: 0.8125rem; opacity: 0.7; }
            .pl-bar { display: inline-block; height: 0.5rem; border-radius: 0.25rem; background: currentColor; opacity: 0.35; vertical-align: middle; }
        </style>
    @endonce

    <x-filament::section>
        <x-slot name="heading">{{ __('admin.polls.results.head') }}</x-slot>
        <dl class="pl-head">
            <div><dt>{{ __('admin.polls.results.status') }}</dt><dd>{{ AdminLabels::value('polls.status', $poll->status->value) }}</dd></div>
            <div><dt>{{ __('admin.polls.results.recipients') }}</dt><dd>{{ $summary['recipients'] }}</dd></div>
            <div><dt>{{ __('admin.polls.results.sent') }}</dt><dd>{{ $sent }}</dd></div>
            <div><dt>{{ __('admin.polls.results.pending') }}</dt><dd>{{ ($statuses['pending'] ?? 0) + ($statuses['sending'] ?? 0) }}</dd></div>
            <div><dt>{{ __('admin.polls.results.skipped') }}</dt><dd>{{ $statuses['skipped'] ?? 0 }}</dd></div>
            <div><dt>{{ __('admin.polls.results.forbidden') }}</dt><dd>{{ $statuses['forbidden'] ?? 0 }}</dd></div>
            <div><dt>{{ __('admin.polls.results.failed') }}</dt><dd>{{ $statuses['failed'] ?? 0 }}</dd></div>
            <div><dt>{{ __('admin.polls.results.unknown') }}</dt><dd>{{ $statuses['unknown'] ?? 0 }}</dd></div>
            <div><dt>{{ __('admin.polls.results.answered') }}</dt><dd>{{ $summary['answered'] }} <small>({{ $share($summary['answered'], $sent) }})</small></dd></div>
        </dl>
        <p class="pl-note">
            @if ($summary['snapshot'])
                {{ __('admin.polls.results.snapshot', ['at' => $summary['at'] !== '' ? AdminDates::dateTime(CarbonImmutable::parse($summary['at'])) : '—']) }}
            @else
                {{ __('admin.polls.results.live') }}
            @endif
            @if ($poll->allows_multiple)
                {{ __('admin.polls.results.multiple_note') }}
            @endif
        </p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('admin.polls.results.options') }}</x-slot>
        <div class="pl-scroll">
            <table class="pl-table">
                <thead>
                    <tr>
                        <th>{{ __('admin.polls.results.option') }}</th>
                        <th class="pl-num">{{ __('admin.polls.results.votes') }}</th>
                        <th class="pl-num">{{ __('admin.polls.results.share') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($summary['options'] as $option)
                        <tr>
                            <td>{{ $option['position'] + 1 }}. {{ $option['label'] }}</td>
                            <td class="pl-num">{{ $option['votes'] }}</td>
                            <td class="pl-num">{{ $share($option['votes'], $summary['answered']) }}</td>
                            <td style="width: 30%"><span class="pl-bar" style="width: {{ $summary['answered'] > 0 ? min(100, (int) round($option['votes'] * 100 / $summary['answered'])) : 0 }}%"></span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    @foreach ($breakdowns as $dimension => $groups)
        <x-filament::section collapsible>
            <x-slot name="heading">{{ __('admin.polls.results.by.'.$dimension) }}</x-slot>
            @if ($groups === [])
                <p class="pl-note">{{ __('admin.polls.results.no_breakdown') }}</p>
            @else
                <div class="pl-scroll">
                    <table class="pl-table">
                        <thead>
                            <tr>
                                <th>{{ __('admin.polls.results.group') }}</th>
                                <th class="pl-num">{{ __('admin.polls.results.answered') }}</th>
                                @foreach ($summary['options'] as $option)
                                    <th class="pl-num" title="{{ $option['label'] }}">{{ $option['position'] + 1 }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($groups as $group)
                                <tr>
                                    <td>{{ $groupLabel($dimension, $group) }}</td>
                                    <td class="pl-num">{{ $group['answered'] }}</td>
                                    @foreach ($summary['options'] as $option)
                                        @php($n = (int) ($group['votes'][$option['id']] ?? 0))
                                        <td class="pl-num">{{ $n }} <small>({{ $share($n, $group['answered']) }})</small></td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endforeach

    <p class="pl-note">{{ __('admin.polls.results.breakdown_note') }}</p>

    {{ $this->table }}
</x-filament-panels::page>
