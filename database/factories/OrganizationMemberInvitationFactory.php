<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationMemberInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrganizationMemberInvitation> */
class OrganizationMemberInvitationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'invited_handle' => '@'.strtolower(fake()->unique()->userName()),
            'learner_id' => null,
            'invited_by_user_id' => null,
            'expires_at' => now()->addDays(7),
            'dm_sent_at' => null,
            'accepted_at' => null,
            'declined_at' => null,
            'revoked_at' => null,
        ];
    }
}
