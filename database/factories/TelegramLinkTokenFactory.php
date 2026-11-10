<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Telegram\Enums\LinkTokenKind;
use App\Models\TelegramLinkToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TelegramLinkToken> */
class TelegramLinkTokenFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'kind' => LinkTokenKind::Account->value,
            'token_hash' => hash('sha256', fake()->unique()->sha1()),
            'user_id' => User::factory(),
            'organization_id' => null,
            'expires_at' => now()->addMinutes(15),
            'used_at' => null,
            'last_result' => null,
            'last_attempt_at' => null,
        ];
    }
}
