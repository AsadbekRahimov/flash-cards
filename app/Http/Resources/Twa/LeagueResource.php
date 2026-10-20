<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Engagement\DTO\LeagueOverview;
use App\Domain\Engagement\DTO\LeagueStanding;
use App\Models\Learner;
use Illuminate\Http\Request;

/**
 * GET /api/twa/league (docs/04 A.13). Of other members only a first name (may be null) and the
 * points leave the server — no ids, usernames or Telegram data.
 *
 * @property LeagueOverview $resource
 */
final class LeagueResource extends TwaResource
{
    /**
     * @return array{
     *   enabled: bool,
     *   opted_out: bool,
     *   week: array{starts_on: string, ends_on: string, closes_at: string},
     *   rules: array{capacity: int, promote: int, demote: int, tiers: list<string>},
     *   membership: array{
     *     tier: int, tier_key: string, rank: int, points: int, members_count: int,
     *     zone: string, points_to_promote: int|null, points_to_safety: int|null,
     *     standings: list<array{rank: int, name: string|null, points: int, is_me: bool}>
     *   }|null,
     *   last_week: array{starts_on: string, tier: int, tier_key: string, result: string, rank: int, points: int}|null
     * }
     */
    public function toArray(Request $request): array
    {
        $overview = $this->resource;
        $membership = $overview->membership;
        $last = $overview->lastResult;
        $lastWeek = $last?->week;
        $tiers = $overview->tiers;
        $top = count($tiers) - 1;

        return [
            'enabled' => $overview->enabled,
            'opted_out' => $overview->optedOut,
            'week' => [
                'starts_on' => $overview->weekStartsOn,
                'ends_on' => $overview->weekEndsOn,
                'closes_at' => $overview->closesAt,
            ],
            'rules' => [
                'capacity' => $overview->capacity,
                'promote' => $overview->promote,
                'demote' => $overview->demote,
                'tiers' => $tiers,
            ],
            'membership' => $membership === null ? null : [
                'tier' => $membership->tier,
                'tier_key' => $membership->tierKey,
                'rank' => $membership->rank,
                'points' => $membership->points,
                'members_count' => $membership->membersCount,
                'zone' => $membership->zone,
                'points_to_promote' => $membership->pointsToPromote,
                'points_to_safety' => $membership->pointsToSafety,
                'standings' => array_values(array_map(
                    fn (LeagueStanding $s): array => [
                        'rank' => $s->rank,
                        'name' => $s->name,
                        'points' => $s->points,
                        'is_me' => $s->learnerId === $this->learnerId($request),
                    ],
                    $membership->standings,
                )),
            ],
            'last_week' => $last === null || $lastWeek === null || $last->result === null ? null : [
                'starts_on' => $lastWeek->starts_on->toDateString(),
                'tier' => $last->tier_before,
                'tier_key' => $tiers[max(0, min($top, $last->tier_before))],
                'result' => $last->result,
                'rank' => (int) $last->final_rank,
                'points' => (int) $last->final_points,
            ],
        ];
    }

    private function learnerId(Request $request): int
    {
        $learner = $request->attributes->get('learner');

        return $learner instanceof Learner ? $learner->id : 0;
    }
}
