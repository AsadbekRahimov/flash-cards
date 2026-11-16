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

    <p class="font-semibold text-danger-600 dark:text-danger-400">{{ __('admin.league_month.confirm.irreversible') }}</p>
    @if ($winners !== [])
        <p>{{ __('admin.league_month.confirm.message') }}</p>
    @endif
</div>
