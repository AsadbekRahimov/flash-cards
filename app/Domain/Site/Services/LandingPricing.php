<?php

declare(strict_types=1);

namespace App\Domain\Site\Services;

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Support\PriceFormatter;

/**
 * What the public landing may say about the Pro price. It follows the decision the TWA and the bot make
 * (`LearnerBillingService::paywall()`: mode `purchase` lowered to `coming_soon` when `CheckoutService::catalog()`
 * has nothing to sell), but from config alone: a public static page has no learner and must not build the payment
 * adapters (the Telegram client among them) just to render a price.
 *
 * Sales are open when `billing.paywall.mode` is `purchase` AND some Pro price is set in a currency an enabled
 * paid provider settles in (Stars: XTR; Payme / Click: UZS). Otherwise the landing keeps its "coming soon" copy,
 * exactly like the paywall of the Mini App.
 */
final class LandingPricing
{
    /**
     * `price`: the monthly Pro price to show (so'm when Payme / Click are on, else Stars), `null` when the month
     * is not for sale in any open currency (e.g. only the year is priced). `starsPrice`: the monthly Stars price,
     * only when it is an addition to `price`. Prices are formatted for `$locale` with non-breaking spaces.
     *
     * @return array{salesOpen: bool, price: string|null, starsPrice: string|null}
     */
    public function forLocale(string $locale): array
    {
        $currencies = config('billing.paywall.mode') === 'purchase' ? $this->openCurrencies() : [];

        if (! $this->anyPriceSet($currencies)) {
            return ['salesOpen' => false, 'price' => null, 'starsPrice' => null];
        }

        $sum = $this->month('UZS', $currencies, $locale);
        $stars = $this->month('XTR', $currencies, $locale);

        return [
            'salesOpen' => true,
            'price' => $sum ?? $stars,
            'starsPrice' => $sum !== null ? $stars : null,
        ];
    }

    /** @return list<string> currencies of the enabled paid providers (each of them serves learners) */
    private function openCurrencies(): array
    {
        $currencies = [];

        foreach (SubscriptionProvider::paid() as $provider) {
            $currency = $provider->currency();

            if ($currency !== null && (bool) config("billing.providers.{$provider->value}.enabled", false)) {
                $currencies[$currency] = true;
            }
        }

        return array_keys($currencies);
    }

    /** @param  list<string>  $currencies */
    private function anyPriceSet(array $currencies): bool
    {
        foreach (array_keys((array) config('billing.periods', [])) as $period) {
            foreach ($currencies as $currency) {
                if ($this->configuredPrice((string) $period, $currency) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param  list<string>  $currencies */
    private function month(string $currency, array $currencies, string $locale): ?string
    {
        $price = in_array($currency, $currencies, true) ? $this->configuredPrice('month', $currency) : null;

        // A non-breaking space keeps "29 000 сум" on one line.
        return $price === null ? null : str_replace(' ', "\u{00A0}", PriceFormatter::format($price, $currency, $locale));
    }

    /** Minor units (tiyin / Stars) of the Pro list price; `null` = not for sale (0, missing, not a number). */
    private function configuredPrice(string $period, string $currency): ?int
    {
        $price = config(sprintf('billing.prices.%s.%s.%s', Plan::Pro->value, $period, $currency));

        return is_numeric($price) && (int) $price > 0 ? (int) $price : null;
    }
}
