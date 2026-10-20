<?php

declare(strict_types=1);

namespace App\Domain\Billing\Contracts;

use App\Domain\Billing\DTO\CheckoutLink;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\ProviderCapabilityException;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\Subscription;

/**
 * A payment provider (docs/13 §2.1): Telegram Stars, Payme, Click (2A.3–2A.5) and the
 * test double FakeBillingProvider. Every method that talks to the provider is called
 * OUTSIDE of a database transaction (docs/13 §3 rule 2).
 */
interface BillingProvider
{
    public function key(): SubscriptionProvider;

    /** Stars: learner + XTR; Payme / Click: learner | organization + UZS. */
    public function supports(string $subscriberType, string $currency): bool;

    /** Creates the payment link / invoice for an already committed order. */
    public function createCheckout(PaymentOrder $order): CheckoutLink;

    /**
     * Stops / resumes the auto-renewal (Stars only).
     *
     * @throws ProviderCapabilityException for a provider that does not renew
     */
    public function cancelAutoRenew(Subscription $subscription): void;

    /** @throws ProviderCapabilityException */
    public function resumeAutoRenew(Subscription $subscription): void;

    /**
     * Refund initiated by us (Stars). Payme / Click refund from their own cabinet and
     * report it through their webhook, so they throw ProviderCapabilityException.
     *
     * @throws ProviderCapabilityException
     */
    public function refund(Payment $payment): void;
}
