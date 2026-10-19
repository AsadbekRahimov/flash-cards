<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Billing\DTO\Checkout;
use Illuminate\Http\Request;

/**
 * POST /api/twa/billing/checkout. `checkout.kind`: `invoice_link` (Stars: the TWA opens it with
 * `openInvoice`) | `redirect` (Payme / Click: `openLink`).
 *
 * @property Checkout $resource
 */
final class CheckoutResource extends TwaResource
{
    /**
     * @return array{
     *   order: array{id: int, token: string, status: string, amount: int, currency: string, expires_at: string},
     *   checkout: array{url: string, kind: string}
     * }
     */
    public function toArray(Request $request): array
    {
        $order = $this->resource->order;

        return [
            'order' => [
                'id' => $order->id,
                'token' => $order->token,
                'status' => $order->status,
                'amount' => $order->amount,
                'currency' => $order->currency,
                'expires_at' => $order->expires_at->toIso8601String(),
            ],
            'checkout' => [
                'url' => $this->resource->link->url,
                'kind' => $this->resource->link->kind,
            ],
        ];
    }
}
