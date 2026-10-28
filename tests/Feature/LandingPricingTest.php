<?php

declare(strict_types=1);

use App\Domain\Site\Services\LandingPricing;
use Illuminate\Support\Facades\Config;

/*
 * The landing says "Pro — coming soon" until Pro is really for sale and then shows the price: the decision
 * mirrors the paywall of the Mini App (billing.paywall.mode = purchase AND something to sell), see LandingPricing.
 */

beforeEach(function (): void {
    Config::set('telegram.bot_username', 'lexiflow_test_bot');
    Config::set('billing.paywall.contact', '');
    Config::set('billing.paywall.mode', 'coming_soon');
    foreach (['telegram_stars', 'payme', 'click'] as $provider) {
        Config::set("billing.providers.{$provider}.enabled", false);
    }
    Config::set('billing.prices.pro', [
        'month' => ['XTR' => 150, 'UZS' => 2_900_000],
        'year' => ['XTR' => 1_300, 'UZS' => 24_900_000],
    ]);
});

/** Pro is for sale: mode `purchase` and the given providers switched on. */
function landingSalesOpen(string ...$providers): void
{
    Config::set('billing.paywall.mode', 'purchase');
    foreach ($providers as $provider) {
        Config::set("billing.providers.{$provider}.enabled", true);
    }
}

// --- LandingPricing --------------------------------------------------------------

it('keeps the sales closed by default (coming_soon, no provider)', function (): void {
    expect((new LandingPricing)->forLocale('ru'))->toBe(['salesOpen' => false, 'price' => null, 'starsPrice' => null]);
});

it('keeps the sales closed unless the mode is purchase AND a provider sells a priced currency', function (callable $configure): void {
    $configure();

    expect((new LandingPricing)->forLocale('ru'))->toBe(['salesOpen' => false, 'price' => null, 'starsPrice' => null]);
})->with([
    'coming_soon although a provider is on' => [function (): void {
        Config::set('billing.providers.payme.enabled', true);
    }],
    'purchase without an enabled provider (the TWA lowers it to coming_soon too)' => [function (): void {
        Config::set('billing.paywall.mode', 'purchase');
    }],
    'purchase, provider on, prices are 0' => [function (): void {
        landingSalesOpen('payme');
        Config::set('billing.prices.pro', ['month' => ['UZS' => 0], 'year' => ['UZS' => 0]]);
    }],
    'purchase, provider on, no prices at all' => [function (): void {
        landingSalesOpen('click');
        Config::set('billing.prices.pro', []);
    }],
    'purchase, provider on, prices are not numbers' => [function (): void {
        landingSalesOpen('payme');
        Config::set('billing.prices.pro', ['month' => ['UZS' => 'abc'], 'year' => ['UZS' => '']]);
    }],
    'purchase, Stars on but only so\'m is priced' => [function (): void {
        landingSalesOpen('telegram_stars');
        Config::set('billing.prices.pro', ['month' => ['UZS' => 2_900_000], 'year' => ['UZS' => 24_900_000]]);
    }],
    'purchase with an unknown mode spelling' => [function (): void {
        landingSalesOpen('payme');
        Config::set('billing.paywall.mode', 'Purchase');
    }],
]);

it('shows the monthly so\'m price from tiyin with non-breaking spaces', function (string $provider): void {
    landingSalesOpen($provider);

    expect((new LandingPricing)->forLocale('ru'))->toBe(['salesOpen' => true, 'price' => "29\u{00A0}000\u{00A0}сум", 'starsPrice' => null])
        ->and((new LandingPricing)->forLocale('uz'))->toBe(['salesOpen' => true, 'price' => "29\u{00A0}000\u{00A0}so'm", 'starsPrice' => null]);
})->with(['payme', 'click']);

it('formats arbitrary configured prices', function (int|string $tiyin, string $expected): void {
    landingSalesOpen('payme');
    Config::set('billing.prices.pro.month.UZS', $tiyin);

    expect((new LandingPricing)->forLocale('ru')['price'])->toBe($expected);
})->with([
    'whole thousands' => [3_900_000, "39\u{00A0}000\u{00A0}сум"],
    'millions' => [1_234_560_000, "12\u{00A0}345\u{00A0}600\u{00A0}сум"],
    'below one thousand' => [90_000, "900\u{00A0}сум"],
    'numeric string from env' => ['4900000', "49\u{00A0}000\u{00A0}сум"],
]);

it('shows the Stars price when only Stars are on', function (): void {
    landingSalesOpen('telegram_stars');

    expect((new LandingPricing)->forLocale('ru'))->toBe(['salesOpen' => true, 'price' => "150\u{00A0}⭐", 'starsPrice' => null]);
});

it('adds the Stars price next to so\'m when both are on', function (): void {
    landingSalesOpen('telegram_stars', 'payme');

    expect((new LandingPricing)->forLocale('ru'))->toBe(['salesOpen' => true, 'price' => "29\u{00A0}000\u{00A0}сум", 'starsPrice' => "150\u{00A0}⭐"]);
});

it('ignores a currency without an enabled provider', function (): void {
    landingSalesOpen('telegram_stars');
    Config::set('billing.providers.payme.enabled', false);

    expect((new LandingPricing)->forLocale('uz')['price'])->toBe("150\u{00A0}⭐");
});

it('opens the sales without a price line when only the year is for sale', function (): void {
    landingSalesOpen('payme');
    Config::set('billing.prices.pro.month', ['UZS' => 0, 'XTR' => 0]);

    expect((new LandingPricing)->forLocale('ru'))->toBe(['salesOpen' => true, 'price' => null, 'starsPrice' => null]);
});

// --- the landing page ------------------------------------------------------------

it('says "coming soon" while Pro is not for sale', function (string $url, string $locale): void {
    $this->get($url)
        ->assertOk()
        ->assertSee('class="badge"', false)
        ->assertSee(__('landing.pricing.soon', [], $locale))
        ->assertSee(__('landing.pricing.subtitle', [], $locale))
        ->assertSee(__('landing.pricing.pro.note', [], $locale))
        ->assertDontSee(__('landing.pricing.subtitle_open', [], $locale))
        ->assertDontSee(__('landing.pricing.pro.note_open', [], $locale))
        ->assertDontSee('class="price"', false)
        ->assertDontSee('plan-featured', false);
})->with([
    'ru' => ['/ru', 'ru'],
    'uz' => ['/uz', 'uz'],
]);

it('promises Pro to the students of a center within the seats of the tariff, not to all of them', function (string $url, string $locale, string $promise, string $oldPromise): void {
    // Above the seats of the tariff a student waits in the queue and stays on Free (docs/13 §6), so "all students" is wrong.
    $this->get($url)
        ->assertOk()
        ->assertSee(__('landing.pricing.centers.features.1', [], $locale))
        ->assertSee($promise)
        ->assertDontSee($oldPromise);
})->with([
    'ru' => ['/ru', 'ru', 'Студенты центра получают Pro в пределах мест тарифа', 'Все студенты центра получают полный Pro'],
    'uz' => ['/uz', 'uz', "tarifdagi o'rinlar doirasida Pro oladi", "to'liq Pro oladi"],
]);

it('says "coming soon" in purchase mode too while nothing can be bought', function (): void {
    Config::set('billing.paywall.mode', 'purchase');

    $this->get('/ru')
        ->assertSee('class="badge"', false)
        ->assertSee(__('landing.pricing.subtitle', [], 'ru'))
        ->assertDontSee('class="price"', false);
});

it('shows the price instead of the badge once Pro is for sale', function (): void {
    landingSalesOpen('payme');

    $this->get('/ru')
        ->assertOk()
        ->assertDontSee('class="badge"', false)
        ->assertDontSee(__('landing.pricing.soon', [], 'ru'))
        ->assertDontSee(__('landing.pricing.subtitle', [], 'ru'))
        ->assertDontSee(__('landing.pricing.pro.note', [], 'ru'))
        ->assertSee(__('landing.pricing.subtitle_open', [], 'ru'))
        ->assertSee(__('landing.pricing.pro.note_open', [], 'ru'))
        ->assertSee("от 29\u{00A0}000\u{00A0}сум в месяц")
        ->assertDontSee('Telegram Stars')
        ->assertSee('plan-featured', false);
});

it('shows the price in Uzbek on /uz', function (): void {
    landingSalesOpen('click');

    $this->get('/uz')
        ->assertDontSee('class="badge"', false)
        ->assertSee(__('landing.pricing.subtitle_open', [], 'uz'))
        ->assertSee("Boshlang'ich narx - oyiga 29\u{00A0}000\u{00A0}so'm")
        ->assertDontSee(__('landing.pricing.soon', [], 'uz'));
});

it('shows both prices when Stars and so\'m are on', function (): void {
    landingSalesOpen('telegram_stars', 'payme');

    $this->get('/ru')
        ->assertSee("от 29\u{00A0}000\u{00A0}сум в месяц")
        ->assertSee("или 150\u{00A0}⭐ через Telegram Stars");
});

it('shows only the Stars price when only Stars are on', function (): void {
    landingSalesOpen('telegram_stars');

    $this->get('/ru')
        ->assertSee("от 150\u{00A0}⭐ в месяц")
        ->assertDontSee('сум в месяц')
        ->assertDontSee('через Telegram Stars');
});

it('does not show a price line without a monthly price but still drops the badge', function (): void {
    landingSalesOpen('payme');
    Config::set('billing.prices.pro.month', ['UZS' => 0, 'XTR' => 0]);

    $this->get('/ru')
        ->assertDontSee('class="badge"', false)
        ->assertDontSee('class="price"', false)
        ->assertSee(__('landing.pricing.subtitle_open', [], 'ru'));
});

it('stays a stateless static page when prices are shown', function (string $url): void {
    landingSalesOpen('telegram_stars', 'payme');

    $response = $this->get($url)->assertOk();
    $html = $response->getContent();

    expect($response->headers->getCookies())->toBe([])
        ->and($html)->not->toContain('<script')
        ->and($html)->not->toContain('csrf');
})->with(['/', '/ru', '/uz']);
