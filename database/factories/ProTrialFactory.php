<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\Services\ProTrialService;
use App\Models\Learner;
use App\Models\ProTrial;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProTrial> */
class ProTrialFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'telegram_hash' => ProTrialService::hashFor($this->faker->unique()->numberBetween(1_000_000, 2_000_000_000)),
            'learner_id' => Learner::factory(),
            'subscription_id' => null,
            'days' => 7,
            'granted_at' => now(),
            'ends_at' => now()->addDays(7),
        ];
    }

    /** The ledger row of a given Telegram account. */
    public function forTelegramId(int $telegramUserId): static
    {
        return $this->state(fn (): array => ['telegram_hash' => ProTrialService::hashFor($telegramUserId)]);
    }
}
