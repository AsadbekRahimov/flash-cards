<div class="space-y-3 text-sm">
    @if ($winners === [])
        <p>{{ __('admin.league_month.confirm.nobody') }}</p>
    @else
        <p class="font-medium">{{ __('admin.league_month.confirm.intro') }}</p>
        <ul class="list-disc ps-5 space-y-1">
            @foreach ($winners as $winner)
                <li>
                    {{ __('admin.league_month.confirm.line', ['place' => $winner['place'], 'name' => $winner['name'], 'points' => trans_choice('leagues.month.points', $winner['points'], ['count' => $winner['points']], 'ru')]) }},
                    {{ $winner['prize'] !== '' ? __('admin.league_month.confirm.prize', ['prize' => $winner['prize']]) : __('admin.league_month.confirm.no_prize') }}
                </li>
            @endforeach
        </ul>
    @endif

    @php($unreachable = array_values(array_filter($winners, static fn (array $w): bool => $w['unreachable'] !== null)))
    @if ($unreachable !== [])
        <p class="rounded-lg bg-warning-50 p-2 text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">
            {{ __('admin.league_month.confirm.unreachable', ['names' => implode(', ', array_map(static fn (array $w): string => $w['place'].' — '.$w['name'].' ('.__('admin.league_month.candidates.state.'.$w['unreachable']).')', $unreachable))]) }}
        </p>
    @endif

    <p class="font-semibold text-danger-600 dark:text-danger-400">{{ __('admin.league_month.confirm.irreversible') }}</p>
    @if ($winners !== [])
        <p>{{ __('admin.league_month.confirm.message') }}</p>
    @endif
</div>
