<?php

declare(strict_types=1);

namespace App\Domain\Billing\Providers;

use App\Domain\Billing\Contracts\BillingProvider;
use App\Domain\Billing\DTO\CheckoutLink;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\ProviderCapabilityException;
use App\Domain\Billing\Providers\Payme\PaymeRpcException;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Payme (Merchant API, docs/13 §2.2, §4.2): "pay for a period" in UZS for learners and
 * organizations. The adapter only builds the checkout link; everything else happens in the
 * JSON-RPC webhook ({@see Payme\PaymeRpcService}) that Payme calls.
 *
 *   createCheckout   `{checkout_url}/{base64("m=<merchant>;ac.order_id=<token>;a=<tiyin>;l=<lang>;c=<return url>")}`
 *                    — the format of docs/13 §4.2, to be confirmed in the Payme sandbox (§15). No HTTP call.
 *   cancel / resume  not a renewing provider -> ProviderCapabilityException.
 *   refund           done in the Payme cabinet; Payme then calls CancelTransaction on a performed
 *                    transaction, which records the refund -> ProviderCapabilityException here
 *                    (admin flow: "returned by hand", 2A.7).
 */
final class PaymeProvider implements BillingProvider
{
    /** Interface languages Payme's checkout page knows. */
    private const CHECKOUT_LANGUAGES = ['ru', 'uz', 'en'];

    public function __construct(private readonly LocaleResolver $locales) {}

    public function key(): SubscriptionProvider
    {
        return SubscriptionProvider::Payme;
    }

    public function supports(string $subscriberType, string $currency): bool
    {
        return in_array($subscriberType, ['learner', 'organization'], true) && $currency === 'UZS';
    }

    public function createCheckout(PaymentOrder $order): CheckoutLink
    {
        if ($order->provider !== $this->key()->value || $order->currency !== 'UZS') {
            throw new InvalidArgumentException("Order #{$order->id} is not a Payme order in UZS.");
        }

        $merchantId = trim((string) config('billing.providers.payme.merchant_id', ''));
        if ($merchantId === '') {
            throw ProviderCapabilityException::unsupported($this->key(), 'checkout without PAYME_MERCHANT_ID');
        }

        $params = self::checkoutParams($order, $merchantId, $this->checkoutLanguage($order));
        $url = rtrim((string) config('billing.providers.payme.checkout_url', 'https://checkout.paycom.uz'), '/').'/'.base64_encode($params);

        return new CheckoutLink($url, CheckoutLink::KIND_REDIRECT, CarbonImmutable::instance($order->expires_at));
    }

    public function cancelAutoRenew(Subscription $subscription): void
    {
        throw ProviderCapabilityException::unsupported($this->key(), 'auto-renewal (Payme sells periods, not subscriptions)');
    }

    public function resumeAutoRenew(Subscription $subscription): void
    {
        throw ProviderCapabilityException::unsupported($this->key(), 'auto-renewal (Payme sells periods, not subscriptions)');
    }

    public function refund(Payment $payment): void
    {
        throw ProviderCapabilityException::unsupported($this->key(), 'refunds initiated by us (refund in the Payme cabinet; Payme then reports it via CancelTransaction)');
    }

    /**
     * The `;`-separated parameter string Payme expects inside the base64 segment. Public so the
     * tests (and the sandbox smoke) can decode and compare it.
     */
    public static function checkoutParams(PaymentOrder $order, string $merchantId, string $lang): string
    {
        return implode(';', [
            'm='.$merchantId,
            'ac.'.PaymeRpcException::ACCOUNT_FIELD.'='.$order->token,
            'a='.$order->amount,
            'l='.$lang,
            'c='.self::returnUrl($order->token, $lang),
        ]);
    }

    /** `/billing/return?order=<token>&lang=<lang>` on APP_URL — never on the Host of a request (docs/07 §2). */
    public static function returnUrl(string $token, string $lang): string
    {
        return rtrim((string) config('app.url'), '/').'/billing/return?'.http_build_query(['order' => $token, 'lang' => $lang]);
    }

    private function checkoutLanguage(PaymentOrder $order): string
    {
        $locale = $order->meta['learner_locale'] ?? null;
        $locale = is_string($locale) && $this->locales->isSupported($locale) ? $locale : $this->locales->default();

        return in_array($locale, self::CHECKOUT_LANGUAGES, true) ? $locale : 'ru';
    }
}
