{{-- Shared body of the offer / privacy pages. $doc is 'offer' or 'privacy'.
     Each section is either a title string (-> placeholder text) or ['title' => ..., 'text' => ...];
     paragraphs are separated by a blank line, a single line break inside a paragraph is kept.
     Tokens in a text: {contact} (support contact) and {requisites} (filled SITE_LEGAL_* fields). --}}
@php
    $tokens = ['{contact}' => $links->legalContact($locale), '{requisites}' => $links->legalRequisites($locale)];
@endphp
<div class="wrap legal-doc">
    <p><a href="{{ $links->pageUrl('home', $locale) }}">&larr; {{ __('legal.back', [], $locale) }}</a></p>
    <h1>{{ __("legal.{$doc}.title", [], $locale) }}</h1>
    @if ($links->legalDraft())
        <p class="notice" role="note">{{ __('legal.draft_notice', [], $locale) }}</p>
    @endif

    @foreach (__("legal.{$doc}.sections", [], $locale) as $i => $section)
        <section>
            <h2>{{ $i + 1 }}. {{ is_array($section) ? $section['title'] : $section }}</h2>
            @if (is_array($section))
                @foreach (preg_split('/\R{2,}/', trim(strtr($section['text'], $tokens))) ?: [] as $paragraph)
                    <p>{!! nl2br(e($paragraph)) !!}</p>
                @endforeach
            @else
                <p class="muted">{{ __('legal.placeholder', [], $locale) }}</p>
            @endif
        </section>
    @endforeach
</div>
