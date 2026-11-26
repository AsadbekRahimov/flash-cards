<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Polls\Enums\PollDeliveryStatus;
use App\Models\Learner;
use App\Models\Poll;
use App\Models\PollDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PollDelivery> */
class PollDeliveryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'poll_id' => Poll::factory(),
            'learner_id' => Learner::factory(),
            // The learner's own Telegram id, as the launch insert copies it.
            'telegram_user_id' => fn (array $attributes): int => $attributes['learner_id'] === null
                ? fake()->unique()->numberBetween(100_000_000, 999_999_999)
                : (int) Learner::query()->whereKey($attributes['learner_id'])->value('telegram_user_id'),
            'is_test' => false,
            'token' => Str::random(12),
            'locale' => 'ru',
            'status' => PollDeliveryStatus::Pending,
        ];
    }

    /** A test send to an administrator: no learner, never in the results. */
    public function test(?int $telegramUserId = null): static
    {
        return $this->state(fn (): array => [
            'learner_id' => null,
            'is_test' => true,
            'telegram_user_id' => $telegramUserId ?? fake()->unique()->numberBetween(100_000_000, 999_999_999),
        ]);
    }

    public function sent(?int $messageId = null): static
    {
        return $this->state(fn (): array => [
            'status' => PollDeliveryStatus::Sent,
            'message_id' => $messageId ?? fake()->numberBetween(1, 1_000_000),
            'sent_at' => now(),
        ]);
    }
}
