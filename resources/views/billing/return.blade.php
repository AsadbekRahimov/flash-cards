@php
    $title = __('billing.return.title', [], $locale);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} — LexiFlow</title>
    <link rel="icon" href="/favicon.ico">
    <link rel="stylesheet" href="/css/site.css">
</head>
<body>
<header class="site-header">
    <div class="wrap bar">
        <span class="brand">LexiFlow</span>
    </div>
</header>

<main>
    <section class="hero">
        <div class="wrap">
            <h1>{{ __("billing.return.{$status}.heading", [], $locale) }}</h1>
            <p class="lead" data-status="{{ $status }}">{{ __("billing.return.{$status}.text", [], $locale) }}</p>
            @if ($botUrl)
                <a class="btn" href="{{ $botUrl }}" rel="noopener">{{ __('billing.return.open_bot', [], $locale) }}</a>
            @endif
            <p class="muted small">{{ __('billing.return.note', [], $locale) }}</p>
            @if ($contact)
                <p class="muted small">{{ __('billing.return.support', [], $locale) }}
                    @if ($contact['href'])
                        <a href="{{ $contact['href'] }}" rel="noopener">{{ $contact['label'] }}</a>
                    @else
                        {{ $contact['label'] }}
                    @endif
                </p>
            @endif
        </div>
    </section>
</main>
</body>
</html>
