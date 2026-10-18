{{-- Shared body of the offer / privacy stubs. $doc is 'offer' or 'privacy'.
     Each section is either a title string (-> placeholder text) or ['title' => ..., 'text' => ...]
     once the owner provides the real text; paragraphs are separated by a blank line. --}}
<div class="wrap legal-doc">
    <p><a href="{{ $links->pageUrl('home', $locale) }}">&larr; {{ __('legal.back', [], $locale) }}</a></p>
    <h1>{{ __("legal.{$doc}.title", [], $locale) }}</h1>
    <p class="notice" role="note">{{ __('legal.draft_notice', [], $locale) }}</p>

    @foreach (__("legal.{$doc}.sections", [], $locale) as $i => $section)
        <section>
            <h2>{{ $i + 1 }}. {{ is_array($section) ? $section['title'] : $section }}</h2>
            @if (is_array($section))
                @foreach (preg_split('/\R{2,}/', trim($section['text'])) ?: [] as $paragraph)
                    <p>{{ $paragraph }}</p>
                @endforeach
            @else
                <p class="muted">{{ __('legal.placeholder', [], $locale) }}</p>
            @endif
        </section>
    @endforeach
</div>
