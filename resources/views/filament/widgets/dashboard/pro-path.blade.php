@php
    use App\Filament\Resources\Support\AdminNumbers;
    use function Filament\Support\generate_href_html;

    /** @var array{days: int, viewed: int, started: int, paid: int, studied: int, matured: int}|null $path */
    /** @var string $reportUrl */
    $share = fn (int $count, int $of): string => $of > 0 ? AdminNumbers::decimal($count / $of * 100, 1, trimZeros: true) : '—';
    $steps = $path === null ? [] : [
        ['key' => 'viewed', 'text' => number_format($path['viewed'], 0, ',', ' '), 'count' => $path['viewed'], 'tone' => 'accent'],
        ['key' => 'started', 'text' => __('admin.dashboard.pro_path.share', ['count' => $path['started'], 'pct' => $share($path['started'], $path['viewed'])]), 'count' => $path['started'], 'tone' => 'accent'],
        ['key' => 'paid', 'text' => __('admin.dashboard.pro_path.share', ['count' => $path['paid'], 'pct' => $share($path['paid'], $path['started'])]), 'count' => $path['paid'], 'tone' => 'accent'],
        ['key' => 'studied', 'text' => __('admin.dashboard.pro_path.of', ['count' => $path['studied'], 'of' => $path['matured'], 'pct' => $share($path['studied'], $path['matured'])]), 'count' => $path['studied'], 'tone' => 'success'],
    ];
    $base = max(1, $path['viewed'] ?? 0);
@endphp

<x-filament-widgets::widget>
    <section class="lx-card lx-block" data-testid="dashboard-pro-path">
        <header class="lx-block-head">
            <h2 class="lx-block-title">{{ __('admin.dashboard.pro_path.heading') }}</h2>
            <a {{ generate_href_html($reportUrl) }} class="lx-small lx-link">{{ __('admin.dashboard.pro_path.report') }}</a>
        </header>

        @if ($path === null)
            <p class="lx-muted lx-small">{{ __('admin.dashboard.pro_path.empty', ['days' => \App\Domain\Billing\Services\BillingFunnelService::DEFAULT_DAYS]) }}</p>
        @else
            @foreach ($steps as $step)
                <div class="lx-bar-row" data-step="{{ $step['key'] }}">
                    <div class="lx-bar-label">
                        <span>{{ __('admin.dashboard.pro_path.'.$step['key']) }}</span>
                        <span class="lx-mono lx-small">{{ $step['text'] }}</span>
                    </div>
                    <div class="lx-bar-track"><div class="lx-bar-fill lx-bar-{{ $step['tone'] }}" style="width: {{ min(100, round($step['count'] / $base * 100, 1)) }}%"></div></div>
                </div>
            @endforeach
            <p class="lx-muted lx-small">{{ __('admin.dashboard.pro_path.meta', ['days' => $path['days']]) }}</p>
        @endif
    </section>
</x-filament-widgets::widget>
