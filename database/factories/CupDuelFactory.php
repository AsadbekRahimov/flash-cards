<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Cup\Enums\CupDuelStatus;
use App\Domain\Practice\Enums\PracticeMode;
use App\Models\Cup;
use App\Models\CupDuel;
use App\Models\CupParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An open challenge of a participant of a cup. The tests of the duel go through `CupDuelService`; the factory is for the schema and the
 * stand (an open challenge: `open_challenger_id` = the challenger, as the CHECK of PostgreSQL wants).
 *
 * @extends Factory<CupDuel>
 */
class CupDuelFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cup_id' => Cup::factory(),
            'day' => 1,
            'challenger_participant_id' => CupParticipant::factory(),
            'opponent_participant_id' => null,
            'is_public' => false,
            'rated_offered' => true,
            'rated' => null,
            'mode' => PracticeMode::Pairs,
            'status' => CupDuelStatus::Open,
            'open_challenger_id' => static fn (array $attributes): mixed => $attributes['challenger_participant_id'],
            'request_id' => (string) Str::uuid(),
            'expires_at' => now()->addMinutes(10),
        ];
    }
}
