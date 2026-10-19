@extends('site.layout')

@section('content')
<section class="hero">
    <div class="wrap">
        <h1>{{ __('landing.hero.title', [], $locale) }}</h1>
        <p class="lead">{{ __('landing.hero.subtitle', [], $locale) }}</p>
        @if ($botUrl)
            <a class="btn" href="{{ $botUrl }}" rel="noopener">{{ __('landing.hero.cta_bot', [], $locale) }}</a>
        @else
            <span class="btn btn-disabled" aria-disabled="true">{{ __('landing.hero.cta_unavailable', [], $locale) }}</span>
        @endif
        <p class="muted">{{ __('landing.hero.note', [], $locale) }}</p>
    </div>
</section>

<section id="how" class="section">
    <div class="wrap">
        <h2>{{ __('landing.how.title', [], $locale) }}</h2>
        <ol class="grid steps">
            @foreach (__('landing.how.steps', [], $locale) as $step)
                <li class="card">
                    <h3>{{ $step['title'] }}</h3>
                    <p>{{ $step['text'] }}</p>
                </li>
            @endforeach
        </ol>
    </div>
</section>

<section class="section alt">
    <div class="wrap">
        <h2>{{ __('landing.features.title', [], $locale) }}</h2>
        <ul class="grid features">
            @foreach (__('landing.features.items', [], $locale) as $item)
                <li class="card">
                    <h3>{{ $item['title'] }}</h3>
                    <p>{{ $item['text'] }}</p>
                </li>
            @endforeach
        </ul>
    </div>
</section>

<section id="pricing" class="section">
    <div class="wrap">
        <h2>{{ __('landing.pricing.title', [], $locale) }}</h2>
        <p class="muted">{{ __('landing.pricing.subtitle', [], $locale) }}</p>
        <div class="grid plans">
            <article class="card plan">
                <h3>{{ __('landing.pricing.free.name', [], $locale) }}</h3>
                <p class="muted">{{ __('landing.pricing.free.tagline', [], $locale) }}</p>
                <ul class="ticks">
                    @foreach (__('landing.pricing.free.features', [], $locale) as $feature)
                        <li>{{ $feature }}</li>
                    @endforeach
                </ul>
            </article>

            <article class="card plan">
                <h3>{{ __('landing.pricing.pro.name', [], $locale) }} <span class="badge">{{ __('landing.pricing.soon', [], $locale) }}</span></h3>
                <p class="muted">{{ __('landing.pricing.pro.tagline', [], $locale) }}</p>
                <ul class="ticks">
                    @foreach (__('landing.pricing.pro.features', [], $locale) as $feature)
                        <li>{{ $feature }}</li>
                    @endforeach
                </ul>
                <p class="muted small">{{ __('landing.pricing.pro.note', [], $locale) }}</p>
            </article>

            <article class="card plan">
                <h3>{{ __('landing.pricing.centers.name', [], $locale) }}</h3>
                <p class="muted">{{ __('landing.pricing.centers.tagline', [], $locale) }}</p>
                <ul class="ticks">
                    @foreach (__('landing.pricing.centers.features', [], $locale) as $feature)
                        <li>{{ $feature }}</li>
                    @endforeach
                </ul>
                @if ($contact)
                    <p>{{ __('landing.pricing.centers.contact', [], $locale) }}
                        @if ($contact['href'])
                            <a href="{{ $contact['href'] }}" rel="noopener">{{ $contact['label'] }}</a>
                        @else
                            {{ $contact['label'] }}
                        @endif
                    </p>
                @endif
            </article>
        </div>
    </div>
</section>
@endsection
