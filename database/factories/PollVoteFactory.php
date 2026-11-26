<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PollDelivery;
use App\Models\PollOption;
use App\Models\PollVote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Give `poll_delivery_id` and `poll_option_id` of ONE poll; `poll_id` is taken from the delivery.
 *
 * @extends Factory<PollVote>
 */
class PollVoteFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'poll_delivery_id' => PollDelivery::factory()->sent(),
            'poll_id' => fn (array $attributes): int => (int) PollDelivery::query()->whereKey($attributes['poll_delivery_id'])->value('poll_id'),
            'poll_option_id' => fn (array $attributes): int => PollOption::factory()->create(['poll_id' => $attributes['poll_id']])->id,
        ];
    }
}
