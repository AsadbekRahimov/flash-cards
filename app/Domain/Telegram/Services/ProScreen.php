<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Billing\DTO\BillingCatalog;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Support\PriceFormatter;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Handlers\ProCallbackHandler;
use App\Models\Learner;
use Carbon\CarbonImmutable;

/**
 * The text and buttons of `/pro` (docs/13 §5): the learner's plan, the auto-renewal state and — when
 * `billing.paywall.mode = purchase` and something is for sale — one button per (period, provider).
 * A button only carries `pro1:buy:<period>:<provider>`; the order is created when it is tapped
 * ({@see ProCallbackHandler}), so opening the menu does not use up the
 * learner's pending-order allowance. Without a sellable provider the screen says "Pro is coming".
 */
final class ProScreen
{
    /** `callback_data` prefix: a versioned format, like `onb1` / `set1`. */
    public const PREFIX = 'pro1';

    public function __construct(
        private readonly LocaleResolver $locales,
        private readonly EntitlementResolver $entitlements,
        private readonly CheckoutService $checkout,
    ) {}

    /**
     * @return array{text: string, markup: array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}|null}
     */
    public function build(Learner $learner): array
    {
        $locale = $this->locales->forLearner($learner);
        $entitlement = $this->entitlements->for($learner);
        $paywall = $this->entitlements->paywall();
        $catalog = $this->checkout->catalog($learner);
        $subscription = $catalog->subscription;

        $lines = [];

        if ($entitlement->plan->rank() > 0) {
            $lines[] = $entitlement->until === null
                ? __('billing.pro.status_pro_forever', [], $locale)
                : __('billing.pro.status_pro_until', ['date' => $this->date($entitlement->until, $learner)], $locale);
        } else {
            $limit = $entitlement->newWordsPerDay;
            $lines[] = $limit === null
                ? __('billing.pro.status_free_generic', [], $locale)
                : __('billing.pro.status_free', ['count' => $limit], $locale);
        }

        if ($subscription !== null && $subscription['auto_renew']) {
            $lines[] = __('billing.pro.renewal_auto', [], $locale);
        } elseif ($subscription !== null && $subscription['can_resume']) {
            $lines[] = __('billing.pro.renewal_canceled', [], $locale);
        }

        $rows = [];
        $selling = $paywall['mode'] === 'purchase' && ! $catalog->isEmpty();

        if (! $selling) {
            $lines[] = __('billing.pro.soon', [], $locale);

            if ($paywall['contact'] !== null) {
                $lines[] = __('billing.pro.contact', ['contact' => $paywall['contact']], $locale);
            }
        } elseif ($subscription === null || ! ($subscription['auto_renew'] || $subscription['perpetual'])) {
            // A live auto-renewal is stopped before paying again: a second payment would double-charge.
            // A perpetual grant has nothing to buy (checkout answers already_perpetual).
            $lines[] = '';
            $lines[] = __('billing.pro.choose', [], $locale);

            ['lines' => $priceLines, 'rows' => $buyRows] = $this->priceOptions($catalog, $locale);
            array_push($lines, ...$priceLines);
            array_push($rows, ...$buyRows);

            if ($catalog->offer !== null) {
                $lines[] = __('billing.pro.offer', ['pct' => $catalog->offer['discount_pct'], 'date' => $this->date(CarbonImmutable::parse($catalog->offer['expires_at']), $learner)], $locale);
            }
        }

        if ($subscription !== null && $subscription['can_cancel']) {
            $rows[] = [['text' => __('billing.pro.cancel_button', [], $locale), 'callback_data' => self::PREFIX.':cancel']];
        } elseif ($subscription !== null && $subscription['can_resume']) {
            $rows[] = [['text' => __('billing.pro.resume_button', [], $locale), 'callback_data' => self::PREFIX.':resume']];
        }

        return [
            'text' => implode("\n", $lines),
            'markup' => $rows === [] ? null : ['inline_keyboard' => $rows],
        ];
    }

    /**
     * The buy buttons of `/pro` for a learner, without the status text (no entitlement lookup): what the
     * post-organization offer DM carries. Empty while nothing is for sale, a live auto-renewal runs or Pro is perpetual.
     *
     * @return list<list<array{text: string, callback_data: string}>>
     */
    public function buyRows(Learner $learner): array
    {
        $catalog = $this->checkout->catalog($learner);

        if ($this->entitlements->paywall()['mode'] !== 'purchase' || $catalog->isEmpty() || ($catalog->subscription !== null && ($catalog->subscription['auto_renew'] || $catalog->subscription['perpetual']))) {
            return [];
        }

        return $this->priceOptions($catalog, $this->locales->forLearner($learner))['rows'];
    }

    /**
     * One price line and one button per (provider, period) of the catalog.
     *
     * @return array{lines: list<string>, rows: list<list<array{text: string, callback_data: string}>>}
     */
    private function priceOptions(BillingCatalog $catalog, string $locale): array
    {
        $lines = [];
        $rows = [];

        foreach ($catalog->items as $item) {
            $period = __("billing.period.{$item['period']}", [], $locale);
            $prices = [];

            foreach ($catalog->providers as $key) {
                $provider = SubscriptionProvider::tryFrom($key);
                $currency = $provider?->currency();

                if ($provider === null || $currency === null || ! isset($item['prices'][$currency])) {
                    continue;
                }

                $price = PriceFormatter::format($item['prices'][$currency], $currency, $locale);
                $prices[$currency] = $price;
                $rows[] = [[
                    'text' => __('billing.pro.buy_button', ['provider' => __("billing.provider.{$key}", [], $locale), 'period' => $period, 'price' => $price], $locale),
                    'callback_data' => self::PREFIX.':buy:'.$item['period'].':'.$key,
                ]];
            }

            $lines[] = __('billing.pro.price_line', ['period' => $period, 'prices' => implode(' / ', $prices)], $locale);
        }

        return ['lines' => $lines, 'rows' => $rows];
    }

    public function date(CarbonImmutable $moment, Learner $learner): string
    {
        return $moment->setTimezone($learner->timezone)->format('d.m.Y');
    }
}
