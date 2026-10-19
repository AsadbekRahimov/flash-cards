<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\TelegramGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Learner> */
class LearnerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'telegram_user_id' => fake()->unique()->numberBetween(100_000_000, 999_999_999),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->optional()->lastName(),
            'username' => fake()->optional()->userName(),
            'language_code' => fake()->randomElement(['en', 'ru', 'uz']),
            'interface_locale' => 'ru',
            'timezone' => (string) config('learners.default_timezone'),
            'daily_goal' => (int) config('learners.default_daily_goal'),
            'reminder_time' => (string) config('learners.default_reminder_time'),
            'onboarding_state' => 'new',
            'meta' => [],
        ];
    }

    /** The learner is (by default an active) member of $group. */
    public function memberOf(TelegramGroup|int $group, string $status = 'active'): static
    {
        $groupId = $group instanceof TelegramGroup ? $group->id : $group;

        return $this->afterCreating(function (Learner $learner) use ($groupId, $status): void {
            GroupMember::factory()->create([
                'telegram_group_id' => $groupId,
                'learner_id' => $learner->id,
                'status' => $status,
                'left_at' => $status === 'active' ? null : now(),
            ]);
        });
    }

    /**
     * The learner is an active member of $organization holding a seat (docs/13 §1.3) — what the
     * sponsored Pro hangs on since 2B.1. `$seatState` `waitlisted` = member without a seat.
     */
    public function seatedIn(Organization|int $organization, string $seatState = 'seated', string $via = 'invite'): static
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;

        return $this->afterCreating(function (Learner $learner) use ($organizationId, $seatState, $via): void {
            OrganizationMember::factory()->create([
                'organization_id' => $organizationId,
                'learner_id' => $learner->id,
                'seat_state' => $seatState,
                'joined_via' => $via,
                'seated_at' => $seatState === 'seated' ? now() : null,
                'waitlisted_at' => $seatState === 'waitlisted' ? now() : null,
            ]);
        });
    }

    public function onboarded(): static
    {
        return $this->state(fn (): array => [
            'onboarding_state' => 'done',
            'onboarding_completed_at' => now(),
        ]);
    }
}
