@php
    use function Filament\Support\generate_href_html;

    /** @var string $heading */
    /** @var string $subheading */
    /** @var array<string, array{label: string, url: string}> $tabs */
    /** @var string $active */
@endphp

<header class="fi-header lx-dash-header">
    <div class="lx-dash-heading">
        <h1 class="fi-header-heading">{{ $heading }}</h1>
        <p class="fi-header-subheading">{{ \Illuminate\Support\Str::ucfirst($subheading) }}</p>
    </div>

    @if ($tabs !== [])
        <nav class="lx-segmented" aria-label="{{ __('admin.dashboard.tabs_label') }}" data-testid="dashboard-tabs">
            @foreach ($tabs as $key => $tab)
                <a
                    {{ generate_href_html($tab['url']) }}
                    @class(['lx-segmented-item', 'lx-segmented-item-active' => $active === $key])
                    @if ($active === $key) aria-current="page" @endif
                    data-tab="{{ $key }}"
                >{{ $tab['label'] }}</a>
            @endforeach
        </nav>
    @endif
</header>
