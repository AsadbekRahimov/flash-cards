{{-- One share of the retention report: null is a dash (never 0 %); otherwise the percent, "count / of" and, for a base under
     WilsonInterval::SMALL_BASE, the Wilson 95 % interval (audit 2026-11-18: show the uncertainty of small cohorts). --}}
@php
    use App\Domain\Analytics\Support\WilsonInterval;
    use App\Filament\Resources\Support\AdminNumbers;

    $interval = is_array($cell) ? WilsonInterval::forSmallBase((int) $cell['count'], (int) $cell['of']) : null;
@endphp
<span class="rt-cell {{ is_array($cell) ? '' : 'rt-dash' }}">
    <strong>{{ is_array($cell) ? AdminNumbers::decimal((float) $cell['pct'], 1).' %' : '—' }}</strong>
    @if (is_array($cell))
        <span class="lm-muted">{{ (int) $cell['count'] }} / {{ (int) $cell['of'] }}</span>
    @endif
    @if ($interval !== null)
        <span class="rt-ci">{{ __('admin.retention.interval', ['low' => AdminNumbers::decimal($interval['low'], 1), 'high' => AdminNumbers::decimal($interval['high'], 1)]) }}</span>
    @endif
</span>
