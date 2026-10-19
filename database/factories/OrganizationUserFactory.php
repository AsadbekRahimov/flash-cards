<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrganizationUser> */
class OrganizationUserFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'role' => OrganizationRole::Owner->value,
            'invited_by_user_id' => null,
        ];
    }

    public function teacher(): static
    {
        return $this->state(fn (): array => ['role' => OrganizationRole::Teacher->value]);
    }
}
