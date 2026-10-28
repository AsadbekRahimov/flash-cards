@extends('site.layout')

@section('content')
<section class="hero">
    <div class="wrap hero-grid">
        <div class="hero-copy">
            <h1>{{ __('landing.hero.title', [], $locale) }}</h1>
            <p class="lead">{{ __('landing.hero.subtitle', [], $locale) }}</p>
            @if ($botUrl)
                <a class="btn" href="{{ $botUrl }}" rel="noopener">{{ __('landing.hero.cta_bot', [], $locale) }}</a>
            @else
                <span class="btn btn-disabled" aria-disabled="true">{{ __('landing.hero.cta_unavailable', [], $locale) }}</span>
            @endif
            <p class="muted">{{ __('landing.hero.note', ['free' => $limits['free']], $locale) }}</p>
        </div>

        {{-- Decorative picture of the app: hidden from assistive technology, no images behind it. --}}
        <div class="hero-art" aria-hidden="true">
            @include('site._phone')
        </div>
    </div>
</section>

<section id="how" class="section">
    <div class="wrap">
        <h2>{{ __('landing.how.title', [], $locale) }}</h2>
        <ol class="grid steps">
            @foreach (__('landing.how.steps', [], $locale) as $step)
                <li class="card step">
                    <span class="step-num" aria-hidden="true">{{ $loop->iteration }}</span>
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
        {{-- One icon per item of landing.features.items, in the same order. --}}
        @php($featureIcons = ['chat', 'clock', 'speaker', 'globe'])
        <ul class="grid features">
            @foreach (__('landing.features.items', [], $locale) as $item)
                <li class="card feature">
                    <span class="feature-icon">@include('site._icon', ['name' => $featureIcons[$loop->index] ?? 'chat'])</span>
                    <div>
                        <h3>{{ $item['title'] }}</h3>
                        <p>{{ $item['text'] }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
</section>

{{-- $pricing (LandingPricing): while Pro is not for sale the section says "coming soon", once it is it shows the price.
     $limits (LandingPricing): the ticks of the Free and Pro cards, with the limits from config filled in. --}}
@php($open = $pricing['salesOpen'])
<section id="pricing" class="section">
    <div class="wrap">
        <h2>{{ __('landing.pricing.title', [], $locale) }}</h2>
        <p class="muted">{{ __($open ? 'landing.pricing.subtitle_open' : 'landing.pricing.subtitle', [], $locale) }}</p>
        <div class="grid plans">
            <article class="card plan">
                <h3>{{ __('landing.pricing.free.name', [], $locale) }}</h3>
                <p class="muted">{{ __('landing.pricing.free.tagline', [], $locale) }}</p>
                <ul class="ticks">
                    @foreach ($limits['freeFeatures'] as $feature)
                        <li>{{ $feature }}</li>
                    @endforeach
                </ul>
            </article>

            <article @class(['card', 'plan', 'plan-featured' => $open])>
                <h3>
                    {{ __('landing.pricing.pro.name', [], $locale) }}
                    @unless ($open)
                        <span class="badge">{{ __('landing.pricing.soon', [], $locale) }}</span>
                    @endunless
                </h3>
                <p class="muted">{{ __('landing.pricing.pro.tagline', [], $locale) }}</p>
                @if ($open && $pricing['price'] !== null)
                    <p class="price">{{ __('landing.pricing.pro.price', ['price' => $pricing['price']], $locale) }}</p>
                    @if ($pricing['starsPrice'] !== null)
                        <p class="price-alt muted">{{ __('landing.pricing.pro.price_stars', ['price' => $pricing['starsPrice']], $locale) }}</p>
                    @endif
                @endif
                <ul class="ticks">
                    @foreach ($limits['proFeatures'] as $feature)
                        <li>{{ $feature }}</li>
                    @endforeach
                </ul>
                <p class="muted small">{{ __($open ? 'landing.pricing.pro.note_open' : 'landing.pricing.pro.note', [], $locale) }}</p>
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
