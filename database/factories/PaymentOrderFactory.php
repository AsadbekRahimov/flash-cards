<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Learner;
use App\Models\Organization;
use App\Models\PaymentOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PaymentOrder> */
class PaymentOrderFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'token' => Str::random(32),
            'subscriber_type' => 'learner',
            'subscriber_id' => Learner::factory(),
            'plan' => 'pro',
            'tier' => null,
            'period' => 'month',
            'provider' => 'telegram_stars',
            'amount' => 150,
            'list_amount' => 150,
            'currency' => 'XTR',
            'offer_id' => null,
            'status' => PaymentOrder::STATUS_PENDING,
            'provider_ref' => null,
            'expires_at' => now()->addDay(),
            'paid_at' => null,
            'subscription_id' => null,
            'created_via' => 'twa',
            'meta' => [],
        ];
    }

    public function forOrganization(?Organization $organization = null, string $tier = 'tutor'): static
    {
        return $this->state(fn (): array => [
            'subscriber_type' => 'organization',
            'subscriber_id' => $organization ?? Organization::factory(),
            'tier' => $tier,
            'provider' => 'payme',
            'currency' => 'UZS',
            'amount' => 12_900_000,
            'list_amount' => 12_900_000,
            'created_via' => 'org_panel',
        ]);
    }

    public function payme(): static
    {
        return $this->state(fn (): array => ['provider' => 'payme', 'currency' => 'UZS', 'amount' => 2_900_000, 'list_amount' => 2_900_000]);
    }

    public function click(): static
    {
        return $this->state(fn (): array => ['provider' => 'click', 'currency' => 'UZS', 'amount' => 2_900_000, 'list_amount' => 2_900_000]);
    }

    public function paid(): static
    {
        return $this->state(fn (): array => ['status' => PaymentOrder::STATUS_PAID, 'paid_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['status' => PaymentOrder::STATUS_EXPIRED, 'expires_at' => now()->subHour()]);
    }
}
