<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Billing\Enums\OfferPlacement;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Support\BillingPeriods;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/twa/billing/checkout — what to buy and how to pay; the amount is never taken from the client.
 *
 * A known period without a price in the provider's currency (0 or no key) is the checkout's 409 `price_not_configured`.
 */
final class CheckoutRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'plan' => ['required', 'string', Rule::enum(Plan::class)],
            'period' => ['required', 'string', Rule::in(BillingPeriods::keys())],
            'provider' => ['required', 'string', Rule::in(array_map(static fn (SubscriptionProvider $p): string => $p->value, SubscriptionProvider::paid()))],
            // Where the purchase sheet was opened from (docs/13 §5.1): analytics only, never changes the order; absent = null.
            'placement' => ['sometimes', 'nullable', 'string', Rule::enum(OfferPlacement::class)],
        ];
    }

    public function plan(): Plan
    {
        return Plan::from($this->string('plan')->toString());
    }

    public function period(): string
    {
        return $this->string('period')->toString();
    }

    public function provider(): SubscriptionProvider
    {
        return SubscriptionProvider::from($this->string('provider')->toString());
    }

    public function placement(): ?OfferPlacement
    {
        return $this->filled('placement') ? OfferPlacement::from($this->string('placement')->toString()) : null;
    }
}
