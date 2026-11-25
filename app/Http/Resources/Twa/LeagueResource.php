<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Engagement\DTO\LeagueMembershipView;
use App\Domain\Engagement\DTO\LeagueOverview;
use App\Domain\Engagement\DTO\LeagueStanding;
use App\Models\Learner;
use Illuminate\Http\Request;

/**
 * GET /api/twa/league (docs/04 A.13). Of other members only a first name (may be null), the points and the emoji of Pro
 * (`pro_emoji`, docs/31 §5а: only while their Pro is live, else null) leave the server — no ids, usernames or Telegram data.
 *
 * A week of the word points v2 (docs/32 §8.3, S12) adds `rules_version: 2`, `best_days` (how many best days of the week count, from
 * the rules of that version), the table of the group (`membership.category`),
 * `membership.rules_version`, `membership.next_gap` (the points that overtake the nearest better place for sure; null for a leader)
 * and the tie-break facts of the rows (`long_words`, `active_days`); the ranks are then competition ranks (1, 2, 2, 4). A v1 week
 * keeps the shape of before: those keys are absent.
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
     *   membership: array<string, mixed>|null,
     *   rules_version?: int,
     *   best_days?: int,
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
        $v2 = $overview->rulesVersion >= 2;

        $payload = [
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
            'membership' => $membership === null ? null : $this->membership($membership, $overview->proEmoji, $this->learnerId($request)),
            'last_week' => $last === null || $lastWeek === null || $last->result === null ? null : [
                'starts_on' => $lastWeek->starts_on->toDateString(),
                'tier' => $last->tier_before,
                'tier_key' => $tiers[max(0, min($top, $last->tier_before))],
                'result' => $last->result,
                'rank' => (int) $last->final_rank,
                'points' => (int) $last->final_points,
            ],
        ];

        if (! $v2) {
            return $payload;
        }

        // Next to `rules_version`, a v2 week only: how many best days of the week count (docs/32 §3.5), from the rules of its version.
        return [
            ...$payload,
            'rules_version' => $overview->rulesVersion,
            ...($overview->bestDays === null ? [] : ['best_days' => $overview->bestDays]),
        ];
    }

    /**
     * @param  array<int, string>  $emoji  learner id => the emoji of Pro
     * @return array<string, mixed>
     */
    private function membership(LeagueMembershipView $membership, array $emoji, int $myId): array
    {
        $v2 = $membership->rulesVersion >= 2;
        $out = [
            'tier' => $membership->tier,
            'tier_key' => $membership->tierKey,
            'rank' => $membership->rank,
            'points' => $membership->points,
            'members_count' => $membership->membersCount,
            'zone' => $membership->zone,
            'points_to_promote' => $membership->pointsToPromote,
            'points_to_safety' => $membership->pointsToSafety,
            'standings' => array_map(
                static fn (LeagueStanding $s): array => [
                    'rank' => $s->rank,
                    'name' => $s->name,
                    'points' => $s->points,
                    'is_me' => $s->learnerId === $myId,
                    'pro_emoji' => $emoji[$s->learnerId] ?? null,
                    ...($v2 ? ['long_words' => (int) $s->longWords, 'active_days' => (int) $s->activeDays] : []),
                ],
                $membership->standings,
            ),
        ];

        return $v2 ? [...$out, 'category' => $membership->category, 'rules_version' => $membership->rulesVersion, 'next_gap' => $membership->nextGap] : $out;
    }

    private function learnerId(Request $request): int
    {
        $learner = $request->attributes->get('learner');

        return $learner instanceof Learner ? $learner->id : 0;
    }
}
