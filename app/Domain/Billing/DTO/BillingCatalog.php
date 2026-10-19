<?php

declare(strict_types=1);

namespace App\Domain\Billing\DTO;

/**
 * What a subscriber can buy right now (docs/13 §2.1 `CheckoutService::catalog`, §5 GET /billing/catalog).
 *
 * `items`: one row per (plan[, tier], period) with the prices per currency after the open offer
 * (`prices`) and before it (`list_prices`); only currencies with a configured price and an
 * enabled provider that supports the subscriber are listed, so an empty `items` means
 * "nothing to sell" (the paywall falls back to coming_soon).
 */
final readonly class BillingCatalog
{
    /**
     * @param  list<array{plan: string, tier: string|null, period: string, prices: array<string, int>, list_prices: array<string, int>}>  $items
     * @param  list<string>  $providers  enabled provider keys usable by this subscriber
     * @param  array{kind: string, discount_pct: int, expires_at: string}|null  $offer
     * @param  array{status: string, provider: string, plan: string, ends_at: string|null, auto_renew: bool, can_cancel: bool, can_resume: bool}|null  $subscription
     */
    public function __construct(
        public array $items,
        public array $providers,
        public ?array $offer,
        public ?array $subscription,
    ) {}

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** @return array{items: list<array<string, mixed>>, providers: list<string>, offer: array<string, mixed>|null, subscription: array<string, mixed>|null} */
    public function toArray(): array
    {
        return ['items' => $this->items, 'providers' => $this->providers, 'offer' => $this->offer, 'subscription' => $this->subscription];
    }
}
