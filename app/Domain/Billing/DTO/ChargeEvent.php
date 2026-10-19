<?php

declare(strict_types=1);

namespace App\Domain\Billing\DTO;

use App\Domain\Billing\Enums\SubscriptionProvider;
use Carbon\CarbonImmutable;

/**
 * Normalized "money received" (docs/13 §2.1): what a provider adapter hands to PaymentApplier.
 */
final readonly class ChargeEvent
{
    /**
     * @param  string  $providerEventId  idempotency key: Stars `telegram_payment_charge_id`; Payme `<id>:perform`; Click `<click_trans_id>:complete`
     * @param  string|null  $providerChargeId  what a refund needs: Stars charge id, Payme transaction id
     * @param  string  $orderToken  `payment_orders.token` (invoice_payload / account.order_id / merchant_trans_id)
     * @param  int  $amount  minor units, as reported by the provider
     * @param  bool  $isRenewal  Stars `is_recurring && !is_first_recurring`
     * @param  CarbonImmutable|null  $periodEndsAt  Stars `subscription_expiration_date`; null = computed from the order period
     * @param  int|null  $providerTransactionId  our `provider_transactions.id` (Payme / Click), linked on the ledger row
     * @param  array<string, mixed>  $raw  normalized event without secrets
     */
    public function __construct(
        public SubscriptionProvider $provider,
        public string $providerEventId,
        public ?string $providerChargeId,
        public string $orderToken,
        public int $amount,
        public string $currency,
        public bool $isRenewal,
        public ?CarbonImmutable $periodEndsAt,
        public CarbonImmutable $occurredAt,
        public array $raw = [],
        public ?int $providerTransactionId = null,
    ) {}
}
