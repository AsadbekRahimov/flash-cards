<?php

declare(strict_types=1);

namespace App\Domain\Site\Services;

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Support\PriceFormatter;
use Illuminate\Support\Str;

/**
 * What the public landing may say about the Pro price. It follows the decision the TWA and the bot make
 * (`LearnerBillingService::paywall()`: mode `purchase` lowered to `coming_soon` when `CheckoutService::catalog()`
 * has nothing to sell), but from config alone: a public static page has no learner and must not build the payment
 * adapters (the Telegram client among them) just to render a price.
 *
 * Sales are open when `billing.paywall.mode` is `purchase` AND some Pro price is set in a currency an enabled
 * paid provider settles in (Stars: XTR; Payme / Click: UZS). Otherwise the landing keeps its "coming soon" copy,
 * exactly like the paywall of the Mini App.
 *
 * The same goes for the limits it quotes (Free's new words per day, the decks of both plans): {@see limits()} reads
 * the numbers the app enforces, so the copy cannot drift from them.
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

    /**
     * The limits the landing quotes, as text in `$locale`; the numbers come from config alone, the ones the app enforces:
     * Free's new words per day (`billing.plans.free.limits.new_words_per_day`, null = no daily limit) and the decks and
     * words per deck of Free and Pro (`decks.limits.*`).
     *
     * `free` is Free's daily allowance as a phrase for the middle of a sentence ("20 новых слов в день"). The ticks of
     * the plan cards are `lang/{locale}/landing.php` `pricing.{free,pro}.features` with their `:new_words` and `:decks`
     * ticks filled in; a tick with nothing to say is dropped: Free without a daily limit has no "N new words" and
     * Pro then has no "no daily limit" to sell either, a plan that cannot make decks (0) has no deck line.
     *
     * @return array{free: string, freeFeatures: list<string>, proFeatures: list<string>}
     */
    public function limits(string $locale): array
    {
        $newWords = Plan::Free->newWordsPerDay();
        $free = $newWords === null
            ? $this->line('landing.limits.free_new_words_unlimited', $locale)
            : trans_choice('landing.limits.free_new_words', $newWords, [], $locale);

        return [
            'free' => $free,
            'freeFeatures' => $this->ticks('free', [':new_words' => Str::ucfirst($free), ':decks' => $this->decks(Plan::Free, $locale)], $locale),
            'proFeatures' => $this->ticks('pro', [
                ':new_words' => $newWords === null ? '' : $this->line('landing.limits.pro_new_words', $locale),
                ':decks' => $this->decks(Plan::Pro, $locale),
            ], $locale),
        ];
    }

    /** "Своя колода до 50 слов" / "Свои колоды: до 20 колод по 2000 слов"; '' for a plan that cannot make decks. */
    private function decks(Plan $plan, string $locale): string
    {
        // Normalised like LearnerDeckService::limits(), which enforces them.
        $decks = max(0, (int) config("decks.limits.{$plan->value}.decks"));
        $words = max(0, (int) config("decks.limits.{$plan->value}.deck_words"));

        return $decks > 0 && $words > 0
            ? trans_choice('landing.limits.decks', $decks, ['decks' => $decks, 'deck_words' => $words], $locale)
            : '';
    }

    /**
     * The ticks of a plan card: its `features` list with every tick that is just a name of `$lines` replaced by the line;
     * an empty line drops the tick.
     *
     * @param  array<string, string>  $lines  `:name` of a tick => its text
     * @return list<string>
     */
    private function ticks(string $plan, array $lines, string $locale): array
    {
        $ticks = [];
        foreach ((array) __("landing.pricing.{$plan}.features", [], $locale) as $tick) {
            $tick = (string) $tick;
            $tick = $lines[$tick] ?? $tick;

            if ($tick !== '') {
                $ticks[] = $tick;
            }
        }

        return $ticks;
    }

    private function line(string $key, string $locale): string
    {
        $line = __($key, [], $locale);

        return is_string($line) ? $line : '';
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
