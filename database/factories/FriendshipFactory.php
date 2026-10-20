<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Engagement\Enums\FriendshipStatus;
use App\Models\Friendship;
use App\Models\Learner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Friendship> */
class FriendshipFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $a = Learner::factory()->create();
        $b = Learner::factory()->create();
        [$low, $high] = $a->id < $b->id ? [$a, $b] : [$b, $a];

        return [
            'learner_id' => $low->id,
            'friend_id' => $high->id,
            'status' => FriendshipStatus::Accepted->value,
            'requested_by_learner_id' => $low->id,
            'accepted_at' => now(),
        ];
    }

    /** The pair of two given learners, in any order (normalised here); `$requester` defaults to the first. */
    public function between(Learner $a, Learner $b, ?Learner $requester = null): static
    {
        [$low, $high] = $a->id < $b->id ? [$a, $b] : [$b, $a];

        return $this->state(fn (): array => [
            'learner_id' => $low->id,
            'friend_id' => $high->id,
            'requested_by_learner_id' => ($requester ?? $a)->id,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => FriendshipStatus::Pending->value, 'accepted_at' => null]);
    }

    public function blocked(): static
    {
        return $this->state(fn (): array => ['status' => FriendshipStatus::Blocked->value, 'accepted_at' => null]);
    }
}
