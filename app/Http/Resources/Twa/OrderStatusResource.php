<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Billing\Services\CheckoutService;
use App\Models\PaymentOrder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * GET /api/twa/billing/orders/{token} — the TWA polls it after `openInvoice` / a return from Payme or Click.
 * `subscription` is filled once the order is `paid` and its subscription still exists.
 * An order canceled by offer bookkeeping (the discount moved to a newer order or was used up) is not a
 * failure: `reason = replaced`, `replaced_by` = the token of the order that took over (never an id), if known.
 *
 * @property PaymentOrder $resource
 */
final class OrderStatusResource extends TwaResource
{
    public const REASON_REPLACED = 'replaced';

    public function __construct(PaymentOrder $resource, private readonly ?string $replacedBy = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array{
     *   status: string,
     *   paid_at: string|null,
     *   reason: string|null,
     *   replaced_by: string|null,
     *   subscription: array{status: string, provider: string, plan: string, ends_at: string|null, auto_renew: bool, can_cancel: bool, can_resume: bool, perpetual: bool}|null
     * }
     */
    public function toArray(Request $request): array
    {
        $order = $this->resource;
        $subscription = $order->status === PaymentOrder::STATUS_PAID ? $order->subscription : null;

        return [
            'status' => $order->status,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'reason' => $order->canceledForOffer() ? self::REASON_REPLACED : null,
            'replaced_by' => $order->canceledForOffer() ? $this->replacedBy : null,
            'subscription' => $subscription === null ? null : CheckoutService::summarize($subscription, CarbonImmutable::now()),
        ];
    }
}
