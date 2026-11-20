@php
    use function Filament\Support\generate_href_html;

    /** @var list<array{level: string, title: string, mono: bool, detail: string, value: string, url: string|null}> $rows */
@endphp

<x-filament-widgets::widget>
    <section class="lx-card lx-block lx-block-list" data-testid="dashboard-attention">
        <header class="lx-block-head">
            <h2 class="lx-block-title">{{ __('admin.dashboard.attention.heading') }}</h2>
        </header>

        @forelse ($rows as $row)
            <div class="lx-row" data-level="{{ $row['level'] }}">
                <span aria-hidden="true" class="lx-dot lx-dot-{{ $row['level'] }}"></span>
                <span class="lx-row-main">
                    @if ($row['url'] !== null)
                        <a {{ generate_href_html($row['url']) }} @class(['lx-row-title', 'lx-mono' => $row['mono']])>{{ $row['title'] }}</a>
                    @else
                        <span @class(['lx-row-title', 'lx-mono' => $row['mono']])>{{ $row['title'] }}</span>
                    @endif
                    <span class="lx-muted lx-small">{{ $row['detail'] }}</span>
                </span>
                @if ($row['value'] !== '')
                    <span class="lx-small lx-row-value lx-text-{{ $row['level'] }}">{{ $row['value'] }}</span>
                @endif
            </div>
        @empty
            <div class="lx-row lx-row-empty" data-testid="dashboard-attention-empty">
                <span aria-hidden="true" class="lx-dot lx-dot-success"></span>
                <span class="lx-row-main">
                    <span class="lx-row-title">{{ __('admin.dashboard.attention.empty') }}</span>
                    <span class="lx-muted lx-small">{{ __('admin.dashboard.attention.empty_help') }}</span>
                </span>
            </div>
        @endforelse
    </section>
</x-filament-widgets::widget>
