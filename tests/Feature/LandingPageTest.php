<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;

/*
 * The landing's first screen shows a picture of the app (a phone drawn with HTML + CSS), the steps are numbered and
 * the feature cards carry icons. All of it stays decorative and self-contained: no images, no external fonts, no
 * inline styles, no scripts (the CSP of these pages is `script-src 'none'`), nothing focusable in the picture.
 */

beforeEach(function (): void {
    Config::set('telegram.bot_username', 'lexiflow_test_bot');
    Config::set('billing.paywall.contact', '');
});

/** @return DOMXPath the landing HTML parsed for structural assertions */
function landingXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();

    return new DOMXPath($document);
}

// --- the phone mock-up -------------------------------------------------------------

it('shows the app picture next to the headline, hidden from assistive technology', function (string $url, array $texts): void {
    $html = $this->get($url)->assertOk()->getContent();
    $xpath = landingXPath($html);

    $art = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " hero-art ")]');
    expect($art)->toHaveCount(1)
        ->and($art->item(0)->getAttribute('aria-hidden'))->toBe('true');

    // The copy comes first in the document (a phone shows it above the picture), the picture second.
    expect(strpos($html, 'class="hero-copy"'))->toBeLessThan(strpos($html, 'class="hero-art"'));

    $inside = $art->item(0)->textContent;
    foreach ($texts as $text) {
        expect($inside)->toContain($text);
    }

    // Decorative only: nothing to tab to, nothing to announce, no image.
    expect($xpath->query('.//a | .//button | .//input | .//select | .//textarea | .//img | .//*[@tabindex]', $art->item(0)))->toHaveCount(0);
})->with([
    'ru' => ['/ru', ['breakfast', '/ˈbrekfəst/', 'завтрак', 'I have breakfast at eight.', 'Новое слово', 'Не помню', 'Помню', 'Легко']],
    'uz' => ['/uz', ['breakfast', '/ˈbrekfəst/', 'nonushta', 'I have breakfast at eight.', 'Yangi so\'z', 'Eslay olmadim', 'Esladim', 'Oson']],
]);

it('takes the picture texts from lang, in the language of the page', function (): void {
    $ru = $this->get('/ru')->getContent();
    $uz = $this->get('/uz')->getContent();

    expect($ru)->toContain(__('landing.hero.mockup.question', [], 'ru'))
        ->not->toContain('Eslay olmadim')
        ->and($uz)->toContain(e(__('landing.hero.mockup.question', [], 'uz')))
        ->not->toContain('Не помню');
});

it('colours the three grades with danger, accent and success', function (): void {
    $html = $this->get('/ru')->getContent();

    expect($html)
        ->toContain('app-btn app-btn-forgot')
        ->toContain('app-btn app-btn-remembered')
        ->toContain('app-btn app-btn-easy');
});

// --- steps and icons -----------------------------------------------------------------

it('numbers the three steps in circles', function (string $url): void {
    $xpath = landingXPath($this->get($url)->getContent());

    $numbers = $xpath->query('//ol[contains(@class, "steps")]/li//span[contains(@class, "step-num")]');
    expect($numbers)->toHaveCount(3);

    foreach ($numbers as $i => $number) {
        expect(trim($number->textContent))->toBe((string) ($i + 1))
            ->and($number->getAttribute('aria-hidden'))->toBe('true');
    }
})->with(['/ru', '/uz']);

it('gives every feature card a decorative inline icon in currentColor', function (string $url, string $locale): void {
    $xpath = landingXPath($this->get($url)->getContent());

    $items = count(__('landing.features.items', [], $locale));
    $icons = $xpath->query('//ul[contains(@class, "features")]/li//svg');

    expect($items)->toBeGreaterThan(0)
        ->and($icons)->toHaveCount($items);

    foreach ($icons as $svg) {
        expect($svg->getAttribute('aria-hidden'))->toBe('true')
            ->and($svg->getAttribute('stroke'))->toBe('currentColor');
    }
})->with([
    'ru' => ['/ru', 'ru'],
    'uz' => ['/uz', 'uz'],
]);

it('keeps every inline svg decorative and free of external references', function (): void {
    $html = $this->get('/ru')->getContent();
    $xpath = landingXPath($html);

    foreach ($xpath->query('//svg') as $svg) {
        // Either the icon itself or its container (the phone picture) is aria-hidden.
        $hidden = $svg->getAttribute('aria-hidden') === 'true' || $xpath->query('ancestor::*[@aria-hidden="true"]', $svg)->length > 0;
        expect($hidden)->toBeTrue();
    }

    expect($html)->not->toContain('<use')->not->toContain('xlink:');
});

// --- self-contained --------------------------------------------------------------------

it('draws the landing without images, inline styles, scripts or external fonts', function (string $url): void {
    $html = $this->get($url)->assertOk()->getContent();

    expect($html)
        ->not->toMatch('/\sstyle\s*=/i')
        ->not->toContain('<style')
        ->not->toContain('<img')
        ->not->toContain('<picture')
        ->not->toContain('<script')
        ->not->toContain('<iframe');

    preg_match_all('/<link\b[^>]*>/i', $html, $links);
    $stylesheets = array_values(array_filter($links[0], fn (string $tag): bool => str_contains($tag, 'rel="stylesheet"')));
    expect($stylesheets)->toBe(['<link rel="stylesheet" href="/css/site.css">']);
})->with(['/', '/ru', '/uz']);

it('ships a stylesheet that loads nothing else', function (): void {
    $css = (string) file_get_contents(public_path('css/site.css'));

    expect($css)->not->toBeEmpty()
        ->not->toContain('@import')
        ->not->toContain('@font-face')
        ->not->toContain('url(')
        ->not->toContain('http');
});

it('uses the dark and light site tokens for the picture instead of fixed colours', function (): void {
    $css = (string) file_get_contents(public_path('css/site.css'));

    // Every .app-* / .phone rule takes colours from custom properties, so the dark scheme restyles it as a whole.
    preg_match_all('/^\.(?:app-|phone|hero-art)[^{]*\{([^}]*)\}/m', $css, $rules);
    expect($rules[1])->not->toBeEmpty();

    foreach ($rules[1] as $declarations) {
        expect($declarations)->not->toMatch('/#[0-9a-fA-F]{3,8}\b/');
    }

    foreach (['--accent-soft', '--accent-ink', '--success-soft', '--success-ink', '--danger-soft', '--danger-ink', '--device', '--screen'] as $token) {
        expect(substr_count($css, $token.':'))->toBe(2, "{$token} must be defined for the light and the dark scheme");
    }
});
