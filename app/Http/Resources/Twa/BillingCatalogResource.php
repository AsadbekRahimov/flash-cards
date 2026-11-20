<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Billing\DTO\BillingCatalog;
use Illuminate\Http\Request;

/**
 * GET /api/twa/billing/catalog
 *
 * `items[].period`: `month` | `quarter` | `year` (a client must skip a period it does not know); `days`: length of the period;
 * `saving_pct`: whole percent cheaper than the same time in months at the month's list price (0 for the month).
 *
 * @property BillingCatalog $resource
 */
final class BillingCatalogResource extends TwaResource
{
    /**
     * @return array{
     *   items: list<array{plan: string, tier: string|null, period: string, days: int, prices: array<string, int>, list_prices: array<string, int>, saving_pct: int}>,
     *   providers: list<string>,
     *   offer: array{kind: string, discount_pct: int, period: string|null, expires_at: string}|null,
     *   subscription: array{status: string, provider: string, plan: string, ends_at: string|null, auto_renew: bool, can_cancel: bool, can_resume: bool, perpetual: bool}|null
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'items' => $this->resource->items,
            'providers' => $this->resource->providers,
            'offer' => $this->resource->offer,
            'subscription' => $this->resource->subscription,
        ];
    }
}
