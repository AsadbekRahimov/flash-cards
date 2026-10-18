<?php

declare(strict_types=1);

use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Site\Services\SiteLinks;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    Config::set('telegram.bot_username', 'lexiflow_test_bot');
    Config::set('site.support_contact', '');
});

// --- pages, locales, lang attribute ---------------------------------------

it('serves every public page with 200 and the right lang attribute', function (string $url, string $lang): void {
    $this->get($url)
        ->assertOk()
        ->assertSee('<html lang="'.$lang.'">', false);
})->with([
    'home default' => ['/', 'ru'],
    'offer default' => ['/offer', 'ru'],
    'privacy default' => ['/privacy', 'ru'],
    'home ru' => ['/ru', 'ru'],
    'offer ru' => ['/ru/offer', 'ru'],
    'privacy ru' => ['/ru/privacy', 'ru'],
    'home uz' => ['/uz', 'uz'],
    'offer uz' => ['/uz/offer', 'uz'],
    'privacy uz' => ['/uz/privacy', 'uz'],
]);

it('renders localized content', function (): void {
    $this->get('/ru')->assertSee(__('landing.hero.title', [], 'ru'))->assertDontSee(__('landing.hero.title', [], 'uz'));
    $this->get('/uz')->assertSee(__('landing.hero.title', [], 'uz'))->assertDontSee(__('landing.hero.title', [], 'ru'));
    $this->get('/uz/offer')->assertSee(__('legal.offer.title', [], 'uz'));
    $this->get('/privacy')->assertSee(__('legal.privacy.title', [], 'ru'));
});

it('returns 404 for unsupported locale prefixes', function (): void {
    $this->get('/en')->assertNotFound();
    $this->get('/de/offer')->assertNotFound();
});

it('negotiates the language from Accept-Language on unprefixed URLs', function (string $header, string $lang): void {
    $response = $this->get('/', ['Accept-Language' => $header]);

    $response->assertOk()->assertSee('<html lang="'.$lang.'">', false);
    expect($response->headers->get('Vary'))->toContain('Accept-Language');
})->with([
    'uz' => ['uz', 'uz'],
    'uz region with q' => ['uz-UZ,uz;q=0.9,en;q=0.8', 'uz'],
    'first supported wins by quality' => ['en-US,ru;q=0.5,uz;q=0.9', 'uz'],
    'unsupported falls back to ru' => ['en-US,de;q=0.9', 'ru'],
    'garbage falls back to ru' => ['*', 'ru'],
]);

it('lets an explicit locale prefix win over Accept-Language', function (): void {
    $response = $this->get('/ru', ['Accept-Language' => 'uz']);

    $response->assertOk()->assertSee('<html lang="ru">', false);
    expect((string) $response->headers->get('Vary'))->not->toContain('Accept-Language');
});

it('defaults to ru without Accept-Language', function (): void {
    $this->get('/')->assertSee('<html lang="ru">', false);
});

it('exposes canonical, hreflang alternates and a language switcher', function (): void {
    $html = $this->get('/uz/privacy')->getContent();

    expect($html)
        ->toContain('<link rel="canonical" href="'.url('/uz/privacy').'">')
        ->toContain('hreflang="ru" href="'.url('/ru/privacy').'"')
        ->toContain('hreflang="uz" href="'.url('/uz/privacy').'"')
        ->toContain('hreflang="x-default" href="'.url('/privacy').'"');
});

it('marks legal stubs as draft and noindex, the landing as indexable', function (): void {
    foreach (['/ru/offer', '/uz/offer', '/ru/privacy', '/uz/privacy'] as $url) {
        $this->get($url)->assertSee('noindex', false);
    }
    $this->get('/ru')->assertDontSee('noindex', false);

    $this->get('/ru/offer')->assertSee(__('legal.draft_notice', [], 'ru'))->assertSee(__('legal.placeholder', [], 'ru'));
    $this->get('/uz/privacy')->assertSee(__('legal.draft_notice', [], 'uz'))->assertSee(__('legal.placeholder', [], 'uz'));
});

it('renders owner-provided legal text instead of the placeholder', function (): void {
    app('translator')->addLines([
        'legal.offer.sections' => [['title' => 'Custom title', 'text' => "First paragraph\n\nSecond <b>paragraph</b>"]],
    ], 'ru');

    $this->get('/ru/offer')
        ->assertSee('1. Custom title')
        ->assertSee('<p>First paragraph</p>', false)
        ->assertSee('<p>Second &lt;b&gt;paragraph&lt;/b&gt;</p>', false)
        ->assertDontSee(__('legal.placeholder', [], 'ru'));
});

// --- security headers / CSP / statelessness -------------------------------

it('applies security headers and CSP to every public page', function (string $url): void {
    $response = $this->get($url);

    $response->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'self'");
})->with(['/', '/offer', '/privacy', '/ru', '/uz', '/uz/offer', '/uz/privacy']);

it('sends HSTS over HTTPS on the landing', function (): void {
    expect($this->get('https://localhost/uz')->headers->get('Strict-Transport-Security'))
        ->toContain('max-age=15552000');
});

it('is stateless: no cookies, no CSRF token, no inline scripts', function (string $url): void {
    $response = $this->get($url);

    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();

    $html = $response->getContent();
    expect($html)->not->toContain('csrf')->not->toContain('<script');
})->with(['/', '/uz', '/offer', '/uz/privacy']);

it('loads only same-origin assets', function (): void {
    $html = $this->get('/uz')->getContent();

    preg_match_all('/(?:href|src)="([^"]+)"/', $html, $m);
    foreach ($m[1] as $url) {
        expect($url)->toMatch('#^(/|\#|mailto:|https://t\.me/|'.preg_quote(url('/'), '#').')#');
    }
    expect(file_exists(public_path('css/site.css')))->toBeTrue();
});

// --- bot link ---------------------------------------------------------------

it('links the bot from config', function (): void {
    $this->get('/ru')->assertSee('href="https://t.me/lexiflow_test_bot"', false);
    $this->get('/uz')->assertSee('href="https://t.me/lexiflow_test_bot"', false);
});

it('accepts a bot username with a leading @', function (): void {
    Config::set('telegram.bot_username', '@lexiflow_test_bot');

    expect((new SiteLinks)->botUrl())->toBe('https://t.me/lexiflow_test_bot');
});

it('hides the bot button when the username is empty or malformed', function (string $username): void {
    Config::set('telegram.bot_username', $username);

    $this->get('/ru')
        ->assertOk()
        ->assertDontSee('t.me/', false)
        ->assertSee(__('landing.hero.cta_unavailable', [], 'ru'));
})->with(['', '   ', 'a"><script>', 'x', 'bad name']);

// --- support contact ----------------------------------------------------------

it('hides the support contact when BILLING_SUPPORT_CONTACT is empty', function (): void {
    foreach (['/ru', '/uz', '/ru/offer'] as $url) {
        $this->get($url)
            ->assertDontSee(__('landing.footer.contact', [], str_contains($url, 'uz') ? 'uz' : 'ru'))
            ->assertDontSee('mailto:', false);
    }
    $this->get('/ru')->assertDontSee(__('landing.pricing.centers.contact', [], 'ru'));
});

it('shows the support contact in footer and pricing when configured', function (): void {
    Config::set('site.support_contact', 'help@example.com');

    $this->get('/ru')
        ->assertSee('href="mailto:help@example.com"', false)
        ->assertSee(__('landing.footer.contact', [], 'ru'))
        ->assertSee(__('landing.pricing.centers.contact', [], 'ru'));
    $this->get('/uz/offer')
        ->assertSee('href="mailto:help@example.com"', false)
        ->assertSee(__('landing.footer.contact', [], 'uz'));
});

it('formats the support contact by kind', function (string $raw, ?array $expected): void {
    Config::set('site.support_contact', $raw);

    expect((new SiteLinks)->supportContact())->toBe($expected);
})->with([
    'empty' => ['', null],
    'blank' => ['   ', null],
    'email' => ['help@example.com', ['label' => 'help@example.com', 'href' => 'mailto:help@example.com']],
    'telegram handle' => ['@lexi_help', ['label' => '@lexi_help', 'href' => 'https://t.me/lexi_help']],
    'https url' => ['https://example.com/support', ['label' => 'https://example.com/support', 'href' => 'https://example.com/support']],
    'javascript url is plain text' => ['javascript:alert(1)', ['label' => 'javascript:alert(1)', 'href' => null]],
    'plain text' => ['Telegram +998 90 000 00 00', ['label' => 'Telegram +998 90 000 00 00', 'href' => null]],
]);

it('escapes a hostile support contact', function (): void {
    Config::set('site.support_contact', '<script>alert(1)</script>');

    $this->get('/ru')->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
});

// --- LocaleResolver::fromAcceptLanguage ----------------------------------------

it('picks the first supported Accept-Language tag', function (array $tags, string $expected): void {
    expect((new LocaleResolver)->fromAcceptLanguage($tags))->toBe($expected);
})->with([
    'empty' => [[], 'ru'],
    'uz' => [['uz'], 'uz'],
    'region underscore' => [['uz_UZ', 'ru'], 'uz'],
    'skips unsupported' => [['en', 'de', 'uz'], 'uz'],
    'none supported' => [['en', 'de'], 'ru'],
    'uppercase' => [['UZ-latn'], 'uz'],
]);

// --- security.txt -----------------------------------------------------------

it('ships a well-formed RFC 9116 security.txt', function (): void {
    $path = public_path('.well-known/security.txt');

    expect(file_exists($path))->toBeTrue();

    $text = (string) file_get_contents($path);
    expect($text)
        ->toMatch('/^Contact: (mailto:|https:\/\/)\S+$/m')
        ->toMatch('/^Expires: \d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/m')
        ->toMatch('/^Preferred-Languages: ru, uz, en$/m');
});
