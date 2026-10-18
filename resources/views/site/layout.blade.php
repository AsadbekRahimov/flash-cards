@php
    $title = $title ?? __('landing.meta.title', [], $locale);
    $description = $description ?? __('landing.meta.description', [], $locale);
    $noindex = $noindex ?? false;
    $canonical = $links->pageUrl($page, $locale);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    @if ($noindex)
        <meta name="robots" content="noindex, follow">
    @endif
    <link rel="canonical" href="{{ $canonical }}">
    @foreach ($locales as $alt)
        <link rel="alternate" hreflang="{{ $alt }}" href="{{ $links->pageUrl($page, $alt) }}">
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ $links->defaultUrl($page) }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="LexiFlow">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:locale" content="{{ $locale === 'uz' ? 'uz_UZ' : 'ru_RU' }}">
    <link rel="icon" href="/favicon.ico">
    <link rel="stylesheet" href="/css/site.css">
</head>
<body>
<header class="site-header">
    <div class="wrap bar">
        <a class="brand" href="{{ $links->pageUrl('home', $locale) }}">LexiFlow</a>
        @if ($page === 'home')
            <nav class="nav" aria-label="{{ __('landing.nav.label', [], $locale) }}">
                <a href="#how">{{ __('landing.nav.how', [], $locale) }}</a>
                <a href="#pricing">{{ __('landing.nav.pricing', [], $locale) }}</a>
            </nav>
        @endif
        <nav class="langs" aria-label="{{ __('landing.footer.language', [], $locale) }}">
            @foreach ($locales as $alt)
                <a href="{{ $links->pageUrl($page, $alt) }}" hreflang="{{ $alt }}" lang="{{ $alt }}" @if ($alt === $locale) aria-current="true" @endif>{{ strtoupper($alt) }}</a>
            @endforeach
        </nav>
    </div>
</header>

<main>
    @yield('content')
</main>

<footer class="site-footer">
    <div class="wrap">
        <p class="muted">{{ __('landing.footer.tagline', [], $locale) }}</p>
        <nav class="legal" aria-label="{{ __('landing.footer.legal_label', [], $locale) }}">
            <a href="{{ $links->pageUrl('offer', $locale) }}">{{ __('legal.offer.title', [], $locale) }}</a>
            <a href="{{ $links->pageUrl('privacy', $locale) }}">{{ __('legal.privacy.title', [], $locale) }}</a>
        </nav>
        @if ($contact)
            <p class="muted">{{ __('landing.footer.contact', [], $locale) }}
                @if ($contact['href'])
                    <a href="{{ $contact['href'] }}" rel="noopener">{{ $contact['label'] }}</a>
                @else
                    {{ $contact['label'] }}
                @endif
            </p>
        @endif
    </div>
</footer>
</body>
</html>
