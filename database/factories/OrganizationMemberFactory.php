<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Organizations\Enums\JoinedVia;
use App\Domain\Organizations\Enums\MemberStatus;
use App\Domain\Organizations\Enums\SeatState;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrganizationMember> */
class OrganizationMemberFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'learner_id' => Learner::factory(),
            'status' => MemberStatus::Active->value,
            'seat_state' => SeatState::Seated->value,
            'joined_via' => JoinedVia::Invite->value,
            'invite_id' => null,
            'seated_at' => now(),
            'waitlisted_at' => null,
            'joined_at' => now(),
            'left_at' => null,
            'meta' => [],
        ];
    }

    public function waitlisted(): static
    {
        return $this->state(fn (): array => [
            'seat_state' => SeatState::Waitlisted->value,
            'seated_at' => null,
            'waitlisted_at' => now(),
        ]);
    }

    public function left(string $status = 'left'): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'seat_state' => SeatState::None->value,
            'seated_at' => null,
            'waitlisted_at' => null,
            'left_at' => now(),
        ]);
    }

    public function viaGroup(): static
    {
        return $this->state(fn (): array => ['joined_via' => JoinedVia::Group->value]);
    }
}
