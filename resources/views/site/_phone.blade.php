{{-- Decorative phone mock-up of the Mini App flash card (docs/14 §6.2). Pure HTML + CSS: no images, no inline styles,
     no scripts. It sits inside an aria-hidden container, so nothing here is focusable or announced. The text comes
     from lang/{ru,uz}/landing.php (`hero.mockup.*`) and follows the page language. --}}
<div class="phone">
    <div class="phone-screen">
        <div class="app-top">
            <svg class="app-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>
            <span class="app-bar"><span class="app-bar-fill"></span></span>
            <span class="app-count">{{ __('landing.hero.mockup.progress', [], $locale) }}</span>
        </div>

        <div class="app-card">
            <span class="app-chip">{{ __('landing.hero.mockup.chip', [], $locale) }}</span>
            <span class="app-sound">@include('site._icon', ['name' => 'speaker'])</span>
            <p class="app-word">{{ __('landing.hero.mockup.word', [], $locale) }}</p>
            <p class="app-ipa">{{ __('landing.hero.mockup.ipa', [], $locale) }}</p>
            <p class="app-translation">{{ __('landing.hero.mockup.translation', [], $locale) }}</p>
            <p class="app-example">{{ __('landing.hero.mockup.example', [], $locale) }}</p>
        </div>

        <div class="app-grade">
            <p class="app-question">{{ __('landing.hero.mockup.question', [], $locale) }}</p>
            <div class="app-buttons">
                <span class="app-btn app-btn-forgot">{{ __('landing.hero.mockup.grade.forgot', [], $locale) }}</span>
                <span class="app-btn app-btn-remembered">{{ __('landing.hero.mockup.grade.remembered', [], $locale) }}</span>
                <span class="app-btn app-btn-easy">{{ __('landing.hero.mockup.grade.easy', [], $locale) }}</span>
            </div>
        </div>
    </div>
</div>
