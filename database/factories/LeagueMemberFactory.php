<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LeagueGroup;
use App\Models\LeagueMember;
use App\Models\Learner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A membership row. `league_week_id` and `tier_before` are copied from the group unless given,
 * and the group's `members_count` is bumped so fixtures look like the service wrote them.
 *
 * @extends Factory<LeagueMember>
 */
class LeagueMemberFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'league_group_id' => LeagueGroup::factory(),
            'league_week_id' => null,
            'learner_id' => Learner::factory(),
            'tier_before' => null,
            'final_points' => null,
            'final_rank' => null,
            'result' => null,
        ];
    }

    public function configure(): static
    {
        return $this
            ->afterMaking(function (LeagueMember $member): void {
                $group = LeagueGroup::query()->findOrFail($member->league_group_id);
                if ($member->getAttribute('league_week_id') === null) {
                    $member->league_week_id = $group->league_week_id;
                }
                if ($member->getAttribute('tier_before') === null) {
                    $member->tier_before = $group->tier;
                }
            })
            ->afterCreating(function (LeagueMember $member): void {
                LeagueGroup::query()->whereKey($member->league_group_id)->increment('members_count');
            });
    }

    public function closed(string $result, int $rank, int $points): static
    {
        return $this->state(fn (): array => ['result' => $result, 'final_rank' => $rank, 'final_points' => $points]);
    }
}
