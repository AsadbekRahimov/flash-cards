@php
    use App\Filament\Widgets\OrgActivationTableWidget;
    use function Filament\Support\generate_href_html;

    /** @var array{median: int|null, reached: int, centers: int, rows: list<array{id: int, name: string, waiting: bool, seconds: int}>} $centers */
    /** @var array<int, string> $urls */
    $days = fn (int $seconds): string => trans_choice('admin.dashboard.days', intdiv($seconds, 86400), ['count' => intdiv($seconds, 86400)]);
@endphp

<x-filament-widgets::widget>
    <section class="lx-card lx-block lx-block-list" data-testid="dashboard-centers">
        <header class="lx-block-head">
            <h2 class="lx-block-title">{{ __('admin.dashboard.centers.heading') }}</h2>
            <span class="lx-muted lx-small">
                {{ $centers['median'] === null ? __('admin.dashboard.centers.median_none') : __('admin.dashboard.centers.median', ['duration' => OrgActivationTableWidget::duration($centers['median'])]) }}
            </span>
        </header>

        @forelse ($centers['rows'] as $row)
            <div class="lx-row" data-center="{{ $row['id'] }}">
                <a {{ generate_href_html($urls[$row['id']] ?? null) }} class="lx-grow lx-row-name">{{ $row['name'] }}</a>
                @php
                    $tone = ! $row['waiting'] ? 'success' : ($row['seconds'] >= 7 * 86400 ? 'danger' : 'warning');
                @endphp
                <span class="lx-pill lx-pill-{{ $tone }}">
                    {{ $row['waiting'] ? __('admin.dashboard.centers.waiting', ['days' => $days($row['seconds'])]) : __('admin.dashboard.centers.done', ['days' => $days($row['seconds'])]) }}
                </span>
            </div>
        @empty
            <p class="lx-row lx-muted lx-small">{{ __('admin.dashboard.centers.empty') }}</p>
        @endforelse
    </section>
</x-filament-widgets::widget>
