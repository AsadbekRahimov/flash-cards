<?php

declare(strict_types=1);

namespace App\Domain\Site\Services;

use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Operations\Support\PureCache;
use App\Http\Middleware\StaticPageCsp;
use Closure;

/**
 * The finished HTML of the public pages (`/`, `/offer`, `/privacy` in every locale; docs/19 K6) in the pure cache: key
 * `site:page:v1:{page}:{locale}:{release}`, the value the string the Blade view rendered, nothing else - the headers (the
 * strict CSP of {@see StaticPageCsp}, `Cache-Control`, `Vary`, the security headers of the global
 * middleware) are put on every response afresh, so a cached page differs from a fresh one by not a byte of its body.
 *
 * The pages read no request, no session and no user, only config and the files their texts live in. The `release` part of
 * the key is a fingerprint of exactly those inputs: the config values listed in {@see CONFIG} (prices, the paywall mode and
 * contact, which providers are on, the free limits, the decks, the bot link, the app URL, the practice switches) and the
 * modification times of the site views, of the language files of the pages and of the PHP files that build the HTML
 * ({@see PHP_SOURCES}: the controller, the pricing and links services, the price formatter, the plan enums, the routes). A deploy
 * that changes a text or only the PHP that assembles the page, or an owner who changes a price and restarts, is therefore a
 * different key at once and the old entry just ages out; there is nothing to invalidate. The TTL (a day) is only the bound on
 * what a release leaves behind in the cache.
 */
final class SitePageCache
{
    private const TTL = 86400;

    /**
     * The config the pages quote, directly or through {@see LandingPricing}, {@see SiteLinks} and the price formatter: a key or
     * a prefix of keys. Secrets are not in it (no provider keys, no tokens): the fingerprint is a hash of these values and a
     * key may only hold ids and hashes (docs/19 §3 rule 8). `SitePageCacheTest` scans the sources of the pages for `config()`
     * calls and fails for a key that is read but not listed here.
     *
     * @var list<string>
     */
    public const CONFIG = [
        'app.url',
        'site.legal',
        'telegram.bot_username',
        'billing.paywall',
        'billing.prices',
        'billing.periods',
        'billing.plans',
        'decks.limits',
        // The tiers of a learning center and the year discount of the "teachers" section (LandingPricing::orgTiers()).
        'billing.org_tiers',
        'billing.org_year_discount_percent',
        'practice.access',
        'practice.plan',
        'practice.battle',
        'practice.free_limits',
        // ProPromises::game() reads it for the Mini App; the pages do not draw it, but the file is one of PHP_SOURCES and the scan wants every key listed.
        'game.free_limits',
        'localization.supported',
        'localization.default',
        'localization.fallback',
    ];

    /**
     * The PHP files that build the HTML besides the views (paths from the project root): what the controller assembles, how a
     * price and a link are made, which plans and providers there are, how a locale is chosen, which routes the links name. A
     * release that changes only one of them changes the page, and its fingerprint must change with it. `SitePageCacheTest`
     * checks that every file exists and that a touched one changes the key, and scans these files (and the views) for
     * `config()` calls that {@see CONFIG} does not list.
     *
     * @var list<string>
     */
    public const PHP_SOURCES = [
        'app/Http/Controllers/PublicSiteController.php',
        'app/Domain/Site/Services/LandingPricing.php',
        'app/Domain/Site/Services/SiteLinks.php',
        'app/Domain/Billing/Support/PriceFormatter.php',
        'app/Domain/Billing/Support/ProPromises.php',
        'app/Domain/Billing/Enums/ProPracticePromise.php',
        'app/Domain/Billing/Enums/Plan.php',
        'app/Domain/Billing/Enums/SubscriptionProvider.php',
        'app/Domain/Localization/Services/LocaleResolver.php',
        'routes/web.php',
    ];

    /** Language files the pages take their texts and prices from, by name. */
    private const LANG_FILES = ['landing', 'legal', 'billing', 'org'];

    /** @param  string|null  $root  the project root the PHP sources are looked up under (the application's, unless a test says otherwise) */
    public function __construct(private readonly ?string $root = null) {}

    /**
     * @param  Closure(): string  $render  the HTML of the page, called when it is not cached
     */
    public function html(string $page, string $locale, Closure $render): string
    {
        /** @var string */
        return PureCache::store()->remember($this->key($page, $locale), self::TTL, $render);
    }

    public function key(string $page, string $locale): string
    {
        return "site:page:v1:{$page}:{$locale}:".$this->release();
    }

    /** A short hash of everything but the page and the locale that the HTML depends on. */
    public function release(): string
    {
        $config = [];
        foreach (self::CONFIG as $key) {
            $config[$key] = config($key);
        }

        // Whether a provider is on decides the price shown; its keys and ids are never read here.
        foreach (SubscriptionProvider::paid() as $provider) {
            $config["billing.providers.{$provider->value}.enabled"] = (bool) config("billing.providers.{$provider->value}.enabled", false);
        }

        return substr(hash('sha256', json_encode([$config, $this->sourceTimes()], JSON_THROW_ON_ERROR)), 0, 16);
    }

    /**
     * When the files the pages are made of last changed (views, texts, PHP): a new deploy touches the ones it changed.
     *
     * @return array<string, int|false>
     */
    private function sourceTimes(): array
    {
        $times = [];

        foreach (glob(resource_path('views/site').'/*.blade.php') ?: [] as $view) {
            $times['view:'.basename($view)] = filemtime($view);
        }

        foreach ((array) config('localization.supported', []) as $locale) {
            foreach (self::LANG_FILES as $file) {
                $path = lang_path("{$locale}/{$file}.php");
                $times["lang:{$locale}/{$file}"] = is_file($path) ? filemtime($path) : false;
            }
        }

        $root = rtrim($this->root ?? base_path(), '/');
        foreach (self::PHP_SOURCES as $relative) {
            $path = "{$root}/{$relative}";
            $times["php:{$relative}"] = is_file($path) ? filemtime($path) : false;
        }

        return $times;
    }
}
