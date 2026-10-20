<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Billing\Contracts\BillingProvider;
use App\Domain\Billing\DTO\ChargeEvent;
use App\Domain\Billing\DTO\CheckoutLink;
use App\Domain\Billing\DTO\RefundEvent;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\ProviderCapabilityException;
use App\Domain\Billing\Services\BillingProviderRegistry;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * In-memory BillingProvider for tests (docs/13 §2.2): returns `https://pay.test/{token}` as the
 * checkout link, records cancel / resume / refund calls and fabricates the provider events a real
 * webhook would deliver (`emitCharge`, `emitRefund`).
 *
 *   $fake = FakeBillingProvider::register(SubscriptionProvider::TelegramStars);   // + enabled in config
 *   $order = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', $fake->key(), null, 'twa');
 *   app(PaymentApplier::class)->applyCharge($fake->emitCharge($order));
 */
final class FakeBillingProvider implements BillingProvider
{
    /** @var list<int> subscription ids passed to cancelAutoRenew() */
    public array $canceled = [];

    /** @var list<int> */
    public array $resumed = [];

    /** @var list<int> payment ids passed to refund() */
    public array $refunded = [];

    /** @var list<int> order ids a checkout link was created for */
    public array $checkouts = [];

    /** When set, createCheckout() throws it (simulates a provider outage). */
    public ?RuntimeException $failCheckoutWith = null;

    /** When set, cancelAutoRenew()/resumeAutoRenew() throw it. */
    public ?RuntimeException $failAutoRenewWith = null;

    private int $sequence = 0;

    public function __construct(private readonly SubscriptionProvider $key = SubscriptionProvider::TelegramStars) {}

    /** Registers a fake in the app's registry and switches the provider on in config. */
    public static function register(SubscriptionProvider $key = SubscriptionProvider::TelegramStars, bool $enabled = true): self
    {
        $fake = new self($key);
        app(BillingProviderRegistry::class)->register($fake);
        config()->set("billing.providers.{$key->value}.enabled", $enabled);

        return $fake;
    }

    public function key(): SubscriptionProvider
    {
        return $this->key;
    }

    public function supports(string $subscriberType, string $currency): bool
    {
        if ($currency !== $this->key->currency()) {
            return false;
        }

        // Stars: learners only (a personal Telegram wallet); Payme / Click: anyone paying in UZS.
        return $this->key !== SubscriptionProvider::TelegramStars || $subscriberType === 'learner';
    }

    public function createCheckout(PaymentOrder $order): CheckoutLink
    {
        if ($this->failCheckoutWith !== null) {
            throw $this->failCheckoutWith;
        }

        $this->checkouts[] = $order->id;

        return new CheckoutLink(
            url: "https://pay.test/{$order->token}",
            kind: $this->key === SubscriptionProvider::TelegramStars ? CheckoutLink::KIND_INVOICE_LINK : CheckoutLink::KIND_REDIRECT,
            expiresAt: CarbonImmutable::instance($order->expires_at),
        );
    }

    public function cancelAutoRenew(Subscription $subscription): void
    {
        $this->requireAutoRenew('cancelAutoRenew');
        $this->canceled[] = $subscription->id;
    }

    public function resumeAutoRenew(Subscription $subscription): void
    {
        $this->requireAutoRenew('resumeAutoRenew');
        $this->resumed[] = $subscription->id;
    }

    public function refund(Payment $payment): void
    {
        if ($this->key !== SubscriptionProvider::TelegramStars) {
            throw ProviderCapabilityException::unsupported($this->key, 'refund');
        }

        $this->refunded[] = $payment->id;
    }

    /**
     * The "money received" event a webhook would deliver for the order. Amount / currency default
     * to the order's; `$eventId` defaults to a fresh id (pass the same one twice to replay).
     *
     * @param  array<string, mixed>  $raw
     */
    public function emitCharge(
        PaymentOrder $order,
        bool $renewal = false,
        ?CarbonImmutable $periodEndsAt = null,
        ?string $eventId = null,
        ?int $amount = null,
        ?string $currency = null,
        ?CarbonImmutable $occurredAt = null,
        array $raw = [],
        ?int $providerTransactionId = null,
    ): ChargeEvent {
        $eventId ??= 'fake-'.$order->token.'-'.(++$this->sequence);

        return new ChargeEvent(
            provider: $this->key,
            providerEventId: $eventId,
            providerChargeId: $eventId,
            orderToken: $order->token,
            amount: $amount ?? $order->amount,
            currency: $currency ?? $order->currency,
            isRenewal: $renewal,
            periodEndsAt: $periodEndsAt,
            occurredAt: $occurredAt ?? CarbonImmutable::now(),
            raw: $raw,
            providerTransactionId: $providerTransactionId,
        );
    }

    /** The refund event for a charge previously applied from this provider. */
    public function emitRefund(Payment $charge, ?string $eventId = null, ?int $amount = null, ?CarbonImmutable $occurredAt = null): RefundEvent
    {
        return new RefundEvent(
            provider: $this->key,
            providerEventId: $eventId ?? 'fake-refund-'.$charge->provider_event_id,
            refundedProviderChargeId: (string) $charge->provider_charge_id,
            amount: $amount ?? $charge->amount,
            currency: $charge->currency,
            occurredAt: $occurredAt ?? CarbonImmutable::now(),
        );
    }

    private function requireAutoRenew(string $capability): void
    {
        if (! $this->key->isAutoRenewing()) {
            throw ProviderCapabilityException::unsupported($this->key, $capability);
        }

        if ($this->failAutoRenewWith !== null) {
            throw $this->failAutoRenewWith;
        }
    }
}
