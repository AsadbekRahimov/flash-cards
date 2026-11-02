<?php

declare(strict_types=1);

use App\Domain\Operations\Support\PureCache;
use App\Domain\Site\Services\SitePageCache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;

/*
 * The public pages from the pure cache (docs/19 K6): the cached body is the rendered HTML, byte for byte what a fresh render
 * gives, under a key that carries a fingerprint of everything the page quotes; the headers are put on every response by the
 * route and the global middleware, so a cached page is as protected as a fresh one.
 */

beforeEach(function (): void {
    config(['cache.pure_store' => 'array']);
    Config::set('telegram.bot_username', 'lexiflow_test_bot');
    Config::set('billing.paywall.contact', '@lexiflow_support');
});

/** Headers that legitimately differ between two responses. */
const SITE_VOLATILE_HEADERS = ['date'];

/** @return array<string, list<string|null>> */
function siteHeaders(TestResponse $response): array
{
    $headers = $response->headers->all();
    foreach (SITE_VOLATILE_HEADERS as $name) {
        unset($headers[$name]);
    }

    ksort($headers);

    return $headers;
}

it('answers from the cache with the very bytes of a fresh render, for every page and locale', function (string $url): void {
    $fresh = $this->get($url)->assertOk();
    $cached = $this->get($url)->assertOk();

    // A third read from an empty cache: the cache changes nothing about what is sent.
    PureCache::store()->flush();
    $again = $this->get($url)->assertOk();

    expect($cached->getContent())->toBe($fresh->getContent())
        ->and($again->getContent())->toBe($fresh->getContent())
        ->and(strlen((string) $fresh->getContent()))->toBeGreaterThan(1000);
})->with(['/', '/offer', '/privacy', '/ru', '/uz', '/ru/offer', '/uz/offer', '/ru/privacy', '/uz/privacy']);

it('renders a page once: the second request does not touch the view', function (): void {
    $renders = 0;
    View::composer('site.landing', function () use (&$renders): void {
        $renders++;
    });

    $this->get('/uz')->assertOk();
    $this->get('/uz')->assertOk();
    $this->get('/uz')->assertOk();

    expect($renders)->toBe(1);

    // Another locale and another page are entries of their own.
    $this->get('/ru')->assertOk();
    expect($renders)->toBe(2);
});

it('sends the same headers with a cached page as with a fresh one: the CSP without scripts, the security headers, the cache and vary rules', function (string $url, bool $negotiated): void {
    $headers = $negotiated ? ['Accept-Language' => 'uz'] : [];
    $fresh = $this->get($url, $headers)->assertOk();
    $cached = $this->get($url, $headers)->assertOk();

    expect(siteHeaders($cached))->toBe(siteHeaders($fresh));

    $csp = (string) $cached->headers->get('Content-Security-Policy');
    expect($csp)->toContain("script-src 'none'")->toContain("default-src 'self'")->toContain("frame-ancestors 'self'");

    $cached->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    expect((string) $cached->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=300')
        ->and((string) $cached->headers->get('Content-Type'))->toContain('text/html');

    // No session, no cookie, no CSRF: a static page.
    expect($cached->headers->getCookies())->toBe([]);

    if ($negotiated) {
        expect((string) $cached->headers->get('Vary'))->toContain('Accept-Language');
    } else {
        expect($cached->headers->has('Vary'))->toBeFalse();
    }
})->with([
    'landing, explicit' => ['/uz', false],
    'landing, negotiated' => ['/', true],
    'offer, explicit' => ['/ru/offer', false],
    'offer, negotiated' => ['/offer', true],
    'privacy, explicit' => ['/uz/privacy', false],
    'privacy, negotiated' => ['/privacy', true],
]);

it('serves the language of the key: a warm Russian page is never the answer for Uzbek, nor the other way round', function (): void {
    $this->get('/ru')->assertOk();
    $this->get('/uz')->assertOk();

    $this->get('/ru')->assertSee(__('landing.hero.title', [], 'ru'))->assertDontSee(__('landing.hero.title', [], 'uz'));
    $this->get('/uz')->assertSee(__('landing.hero.title', [], 'uz'))->assertDontSee(__('landing.hero.title', [], 'ru'));
    $this->get('/', ['Accept-Language' => 'uz'])->assertSee('<html lang="uz">', false);
    $this->get('/', ['Accept-Language' => 'ru'])->assertSee('<html lang="ru">', false);

    $cache = app(SitePageCache::class);
    expect($cache->key('home', 'ru'))->not->toBe($cache->key('home', 'uz'))
        ->and($cache->key('home', 'ru'))->not->toBe($cache->key('offer', 'ru'))
        ->and($cache->key('home', 'ru'))->toMatch('/^site:page:v1:home:ru:[0-9a-f]{16}$/');
});

it('keeps the HTML as a string under the key of the page', function (): void {
    $this->get('/uz/offer')->assertOk();

    $stored = PureCache::store()->get(app(SitePageCache::class)->key('offer', 'uz'));

    expect($stored)->toBeString()->toContain('<html lang="uz">');
});

it('does not serve an old price or an old link after the config changes: the page is a new key', function (): void {
    Config::set('billing.paywall.mode', 'purchase');
    Config::set('billing.providers.payme.enabled', true);
    Config::set('billing.prices.pro.month.UZS', 2_900_000);
    // The landing keeps "29 000 сум" on one line with a non-breaking space.
    $this->get('/ru')->assertOk()->assertSee("29\u{00A0}000");

    Config::set('billing.prices.pro.month.UZS', 3_900_000);
    $this->get('/ru')->assertOk()->assertSee("39\u{00A0}000")->assertDontSee("29\u{00A0}000");

    Config::set('billing.paywall.mode', 'coming_soon');
    $this->get('/ru')->assertOk()->assertDontSee("39\u{00A0}000")->assertSee(__('landing.pricing.soon', [], 'ru'));

    Config::set('telegram.bot_username', 'another_bot');
    $this->get('/ru')->assertOk()->assertSee('https://t.me/another_bot')->assertDontSee('https://t.me/lexiflow_test_bot');

    Config::set('billing.paywall.contact', '@new_support');
    $this->get('/ru/privacy')->assertOk()->assertSee('https://t.me/new_support')->assertDontSee('https://t.me/lexiflow_support');
});

it('changes the key with each input the pages quote', function (string $key, mixed $value): void {
    $cache = app(SitePageCache::class);
    $before = $cache->key('home', 'ru');

    Config::set($key, $value);

    expect($cache->key('home', 'ru'))->not->toBe($before, "{$key} does not reach the key");
})->with([
    'app url' => ['app.url', 'https://another.example'],
    'bot username' => ['telegram.bot_username', 'other_bot'],
    'paywall mode' => ['billing.paywall.mode', 'purchase'],
    'paywall contact' => ['billing.paywall.contact', 'support@example.test'],
    'month price' => ['billing.prices.pro.month.UZS', 1_000_000],
    'year price' => ['billing.prices.pro.year.XTR', 999],
    'periods' => ['billing.periods', ['month' => 31, 'year' => 365]],
    'free new words' => ['billing.plans.free.limits.new_words_per_day', 7],
    'a provider switched on' => ['billing.providers.click.enabled', true],
    'deck limits' => ['decks.limits.pro.decks', 99],
    'practice access' => ['practice.access', 'all'],
    'free practice limit' => ['practice.free_limits.sessions_per_day', 9],
    'locales' => ['localization.supported', ['ru']],
]);

it('keeps secrets out of the key: a provider key or a bot token changes nothing', function (): void {
    $cache = app(SitePageCache::class);
    $before = $cache->release();

    Config::set('billing.providers.payme.key', 'a-secret-key');
    Config::set('billing.providers.click.secret_key', 'another-secret');
    Config::set('telegram.bot_token', '123:token');

    expect($cache->release())->toBe($before);
});

it('changes the key when a text file of the pages is changed: a deploy needs no flush', function (): void {
    $lang = sys_get_temp_dir().'/lexiflow-lang-'.bin2hex(random_bytes(4));
    foreach (['ru', 'uz'] as $locale) {
        File::ensureDirectoryExists("{$lang}/{$locale}");
        foreach (['landing', 'legal', 'billing'] as $file) {
            File::copy(lang_path("{$locale}/{$file}.php"), "{$lang}/{$locale}/{$file}.php");
        }
    }
    app()->useLangPath($lang);

    try {
        $cache = app(SitePageCache::class);
        $before = $cache->release();
        expect($cache->release())->toBe($before, 'stable while nothing changes');

        touch("{$lang}/uz/legal.php", time() + 120);

        expect($cache->release())->not->toBe($before);
    } finally {
        File::deleteDirectory($lang);
    }
});

it('lists every config key the pages read: a new config() call in them must be added to the fingerprint', function (): void {
    $sources = [
        ...(glob(resource_path('views/site/*.blade.php')) ?: []),
        app_path('Http/Controllers/PublicSiteController.php'),
        app_path('Domain/Site/Services/LandingPricing.php'),
        app_path('Domain/Site/Services/SiteLinks.php'),
        app_path('Domain/Billing/Support/PriceFormatter.php'),
        app_path('Domain/Billing/Support/ProPromises.php'),
        app_path('Domain/Billing/Enums/Plan.php'),
        app_path('Domain/Billing/Enums/SubscriptionProvider.php'),
        app_path('Domain/Localization/Services/LocaleResolver.php'),
    ];

    $uncovered = [];
    foreach ($sources as $path) {
        preg_match_all('/\bconfig\(\s*(?:sprintf\(\s*)?[\'"]([a-z0-9_.%{$>\-]+)/i', (string) file_get_contents($path), $found);

        foreach ($found[1] as $key) {
            // A key built at run time (`billing.plans.{$plan}.limits`, `...%s...`) is read up to its first placeholder.
            $key = rtrim((string) preg_split('/[%{$]/', $key)[0], '.');
            $covered = $key === 'billing.providers'
                || collect(SitePageCache::CONFIG)->contains(fn (string $listed): bool => $key === $listed || str_starts_with($key, $listed.'.') || str_starts_with($listed, $key.'.'));

            if (! $covered) {
                $uncovered[] = basename($path).': '.$key;
            }
        }
    }

    expect($uncovered)->toBe([]);
});

it('does not cache the pages of a locale that does not exist', function (): void {
    $this->get('/en')->assertNotFound();
    $this->get('/de/offer')->assertNotFound();

    expect(PureCache::store()->has('site:page:v1:home:en:'.app(SitePageCache::class)->release()))->toBeFalse();
});
