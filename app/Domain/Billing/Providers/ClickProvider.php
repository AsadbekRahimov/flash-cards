<?php

declare(strict_types=1);

namespace App\Domain\Billing\Providers;

use App\Domain\Billing\Contracts\BillingProvider;
use App\Domain\Billing\DTO\CheckoutLink;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\ProviderCapabilityException;
use App\Domain\Billing\Providers\Click\ClickAmount;
use App\Domain\Billing\Support\BillingReturnUrl;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Click (Merchant API, docs/13 §2.2, §4.3): "pay for a period" in UZS for learners and
 * organizations. The adapter only builds the checkout link; the money moves in the Prepare /
 * Complete webhooks ({@see Click\ClickMerchantService}) that Click calls.
 *
 *   createCheckout   `{checkout_url}?service_id=&merchant_id=&amount=<UZS, two decimals>&transaction_param=<token>
 *                    &return_url=<APP_URL>/billing/return?order=<token>&lang=<l>[&merchant_user_id=]`
 *                    — the format of docs/13 §4.3, to be confirmed in the Click test flow (§15). No HTTP call.
 *   cancel / resume  not a renewing provider -> ProviderCapabilityException.
 *   refund           done in the Click cabinet; Click sends no refund notification, so a superadmin
 *                    records it by hand in the panel ("returned by hand") -> ProviderCapabilityException here.
 */
final class ClickProvider implements BillingProvider
{
    public function __construct(private readonly LocaleResolver $locales) {}

    public function key(): SubscriptionProvider
    {
        return SubscriptionProvider::Click;
    }

    public function supports(string $subscriberType, string $currency): bool
    {
        return in_array($subscriberType, ['learner', 'organization'], true) && $currency === 'UZS';
    }

    public function createCheckout(PaymentOrder $order): CheckoutLink
    {
        if ($order->provider !== $this->key()->value || $order->currency !== 'UZS') {
            throw new InvalidArgumentException("Order #{$order->id} is not a Click order in UZS.");
        }

        $serviceId = trim((string) config('billing.providers.click.service_id', ''));
        $merchantId = trim((string) config('billing.providers.click.merchant_id', ''));
        if ($serviceId === '' || $merchantId === '') {
            throw ProviderCapabilityException::unsupported($this->key(), 'checkout without CLICK_SERVICE_ID / CLICK_MERCHANT_ID');
        }

        $query = self::checkoutQuery($order, $serviceId, $merchantId, trim((string) config('billing.providers.click.merchant_user_id', '')), $this->checkoutLanguage($order));
        $url = rtrim((string) config('billing.providers.click.checkout_url', 'https://my.click.uz/services/pay'), '?').'?'.http_build_query($query);

        return new CheckoutLink($url, CheckoutLink::KIND_REDIRECT, CarbonImmutable::instance($order->expires_at));
    }

    public function cancelAutoRenew(Subscription $subscription): void
    {
        throw ProviderCapabilityException::unsupported($this->key(), 'auto-renewal (Click sells periods, not subscriptions)');
    }

    public function resumeAutoRenew(Subscription $subscription): void
    {
        throw ProviderCapabilityException::unsupported($this->key(), 'auto-renewal (Click sells periods, not subscriptions)');
    }

    public function refund(Payment $payment): void
    {
        throw ProviderCapabilityException::unsupported($this->key(), 'refunds initiated by us (refund in the Click cabinet, then record it here as returned by hand)');
    }

    /**
     * The query parameters of the Click payment page. Public so the tests (and the sandbox smoke)
     * can compare them.
     *
     * @return array<string, string>
     */
    public static function checkoutQuery(PaymentOrder $order, string $serviceId, string $merchantId, string $merchantUserId, string $lang): array
    {
        $query = [
            'service_id' => $serviceId,
            'merchant_id' => $merchantId,
            'amount' => ClickAmount::format($order->amount),
            'transaction_param' => $order->token,
            'return_url' => BillingReturnUrl::for($order->token, $lang),
        ];
        if ($merchantUserId !== '') {
            $query['merchant_user_id'] = $merchantUserId;
        }

        return $query;
    }

    private function checkoutLanguage(PaymentOrder $order): string
    {
        $locale = $order->meta['learner_locale'] ?? null;

        return is_string($locale) && $this->locales->isSupported($locale) ? $locale : $this->locales->default();
    }
}
