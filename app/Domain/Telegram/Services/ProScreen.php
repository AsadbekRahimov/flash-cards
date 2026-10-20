<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

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
        } elseif ($subscription === null || ! $subscription['auto_renew']) {
            // A live auto-renewal is stopped before paying again: a second payment would double-charge.
            $lines[] = '';
            $lines[] = __('billing.pro.choose', [], $locale);

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

    public function date(CarbonImmutable $moment, Learner $learner): string
    {
        return $moment->setTimezone($learner->timezone)->format('d.m.Y');
    }
}
