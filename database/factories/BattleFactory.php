<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Practice\DTO\Snapshot;
use App\Domain\Practice\Enums\BattleRole;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Enums\PracticeMode;
use App\Models\Battle;
use App\Models\BattleParticipant;
use App\Models\Course;
use App\Models\Learner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A battle as a row (docs/16 §2.7): by default an `invited` one with an empty snapshot, no participants. {@see withParticipants()}
 * adds the two rows of `battle_participants` the way the invitation does (the challenger busy with the match).
 *
 * @extends Factory<Battle>
 */
class BattleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'challenger_id' => Learner::factory(),
            'opponent_id' => Learner::factory(),
            'friendship_id' => null,
            'course_id' => Course::factory()->published(),
            'lesson_id' => null,
            'mode' => PracticeMode::Pairs,
            'status' => BattleStatus::Invited,
            'end_reason' => null,
            'snapshot' => (new Snapshot(PracticeMode::Pairs, ['ru'], [], [], 'course', ['ru' => 'Курс']))->toArray(),
            'current_round' => 0,
            'phase' => null,
            'round_deadline_at' => null,
            'phase_ends_at' => null,
            'invite_expires_at' => now()->addMinutes(5),
            'room_expires_at' => null,
            'accepted_at' => null,
            'starts_at' => null,
            'finished_at' => null,
            'winner_learner_id' => null,
            'rematch_of_id' => null,
            'state_version' => 0,
            'meta' => [],
        ];
    }

    /** The two participants: the challenger is busy with the match, the opponent is not until the acceptance. */
    public function withParticipants(): static
    {
        return $this->afterCreating(function (Battle $battle): void {
            BattleParticipant::factory()->create([
                'battle_id' => $battle->id,
                'learner_id' => $battle->challenger_id,
                'role' => BattleRole::Challenger,
                'active_learner_id' => $battle->status->isTerminal() ? null : $battle->challenger_id,
            ]);
            BattleParticipant::factory()->create([
                'battle_id' => $battle->id,
                'learner_id' => $battle->opponent_id,
                'role' => BattleRole::Opponent,
                'active_learner_id' => $battle->status->isLive() ? $battle->opponent_id : null,
            ]);
        });
    }
}
