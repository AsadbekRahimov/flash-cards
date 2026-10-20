<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PaymentOrder;
use App\Models\ProviderTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProviderTransaction> */
class ProviderTransactionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider' => 'payme',
            'provider_transaction_id' => Str::lower(Str::random(24)),
            'payment_order_id' => PaymentOrder::factory()->payme(),
            'state' => ProviderTransaction::STATE_CREATED,
            'amount' => 2_900_000,
            'provider_time' => (int) (now()->getTimestampMs()),
            'created_at_provider' => now(),
            'performed_at' => null,
            'canceled_at' => null,
            'perform_time' => null,
            'cancel_time' => null,
            'cancel_reason' => null,
            'raw' => [],
            'history' => [],
        ];
    }

    public function performed(): static
    {
        return $this->state(fn (): array => ['state' => ProviderTransaction::STATE_PERFORMED, 'performed_at' => now(), 'perform_time' => (int) now()->getTimestampMs()]);
    }

    public function canceled(int $reason = 3): static
    {
        return $this->state(fn (): array => ['state' => ProviderTransaction::STATE_CANCELED, 'canceled_at' => now(), 'cancel_time' => (int) now()->getTimestampMs(), 'cancel_reason' => $reason]);
    }
}
