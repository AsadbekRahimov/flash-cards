@include('filament.pages.partials.league-month-styles')

@php
    use App\Filament\Resources\Support\AdminLabels;

    $overflow ??= '';
    $line = fn (array $winner): string => __('admin.league_month.confirm.line', ['place' => $winner['place'], 'name' => $winner['name'], 'points' => trans_choice('leagues.month.points', $winner['points'], ['count' => $winner['points']], 'ru')]).', '
        .($winner['prize'] !== '' ? __('admin.league_month.confirm.prize', ['prize' => $winner['prize']]) : __('admin.league_month.confirm.no_prize'));
    // A v2 month lists the winners by category (Free, then Pro); a v1 month has one list.
    $groups = [];
    foreach ($winners as $winner) {
        $groups[$winner['category'] ?? ''][] = $winner;
    }
@endphp

<div class="lm-confirm">
    @if ($winners === [])
        <p>{{ __('admin.league_month.confirm.nobody') }}</p>
    @else
        <p><strong>{{ __('admin.league_month.confirm.intro') }}</strong></p>
        @foreach ($groups as $category => $list)
            @if ($category !== '')
                <p><strong>{{ AdminLabels::value('learners.score_days.category', $category) }}</strong></p>
            @endif
            <ul>
                @foreach ($list as $winner)
                    <li>{{ $line($winner) }}</li>
                @endforeach
            </ul>
        @endforeach
    @endif

    @if ($overflow !== '')
        <p class="lm-alert">{{ __('admin.league_month.confirm.overflow', ['tie' => $overflow]) }}</p>
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
