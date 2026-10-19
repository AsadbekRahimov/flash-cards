<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Learner;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'payment_order_id' => null,
            'provider_transaction_id' => null,
            'subscription_id' => null,
            'subscriber_type' => 'learner',
            'subscriber_id' => Learner::factory(),
            'provider' => 'telegram_stars',
            'provider_event_id' => 'evt_'.Str::random(20),
            'provider_charge_id' => 'chg_'.Str::random(20),
            'kind' => Payment::KIND_CHARGE,
            'status' => Payment::STATUS_SUCCEEDED,
            'amount' => 150,
            'currency' => 'XTR',
            'refunded_payment_id' => null,
            'period_starts_at' => now(),
            'period_ends_at' => now()->addDays(30),
            'occurred_at' => now(),
            'raw' => [],
        ];
    }

    public function refunded(): static
    {
        return $this->state(fn (): array => ['status' => Payment::STATUS_REFUNDED]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => ['status' => Payment::STATUS_FAILED, 'subscription_id' => null]);
    }
}
