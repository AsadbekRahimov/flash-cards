@include('filament.pages.partials.league-month-styles')

<div class="lm-confirm">
    @if ($winners === [])
        <p>{{ __('admin.league_month.confirm.nobody') }}</p>
    @else
        <p><strong>{{ __('admin.league_month.confirm.intro') }}</strong></p>
        <ul>
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
        <p class="lm-alert">
            {{ __('admin.league_month.confirm.unreachable', ['names' => implode(', ', array_map(static fn (array $w): string => $w['place'].' — '.$w['name'].' ('.__('admin.league_month.candidates.state.'.$w['unreachable']).')', $unreachable))]) }}
        </p>
    @endif

    <p class="lm-danger">{{ __('admin.league_month.confirm.irreversible') }}</p>
    @if ($winners !== [])
        <p>{{ __('admin.league_month.confirm.message') }}</p>
    @endif
</div>
