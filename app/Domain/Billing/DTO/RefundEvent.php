<?php

declare(strict_types=1);

namespace App\Domain\Billing\DTO;

use App\Domain\Billing\Enums\SubscriptionProvider;
use Carbon\CarbonImmutable;

/** Normalized "money returned" (docs/13 §2.1). */
final readonly class RefundEvent
{
    /**
     * @param  string  $providerEventId  idempotency key: Payme `<id>:cancel`, Click `<click_trans_id>:refund:<n>`, Stars charge id of the refund
     * @param  string  $refundedProviderChargeId  `payments.provider_charge_id` of the charge being refunded
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public SubscriptionProvider $provider,
        public string $providerEventId,
        public string $refundedProviderChargeId,
        public int $amount,
        public string $currency,
        public CarbonImmutable $occurredAt,
        public array $raw = [],
    ) {}
}
