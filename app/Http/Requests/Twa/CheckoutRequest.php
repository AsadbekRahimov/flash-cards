<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Billing\Enums\OfferPlacement;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Support\BillingPeriods;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/twa/billing/checkout — what to buy and how to pay; the amount is never taken from the client.
 *
 * A period that is not sold in the provider's currency by design (the quarter: Stars only, no UZS price row) is a 422 on
 * `period` here; a period that is sold in it but has no price yet stays the checkout's 409 `price_not_configured`.
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

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $period = $this->period();
                $currency = $this->provider()->currency();

                if ($currency !== null && ! BillingPeriods::listsCurrency($this->plan()->value, $period, $currency)) {
                    $validator->errors()->add('period', __('billing.pro.errors.period_not_for_provider', [
                        'period' => __("billing.period.{$period}"),
                        'provider' => __('billing.provider.'.$this->provider()->value),
                    ]));
                }
            },
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
