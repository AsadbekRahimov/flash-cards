<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Organizations\Services\OrganizationInviteService;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrganizationInvite> */
class OrganizationInviteFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'code' => OrganizationInviteService::generateCode(),
            'label' => null,
            'telegram_group_id' => null,
            'max_uses' => null,
            'uses_count' => 0,
            'expires_at' => null,
            'is_active' => true,
            'created_by_user_id' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }

    public function exhausted(): static
    {
        return $this->state(fn (): array => ['max_uses' => 1, 'uses_count' => 1]);
    }
}
