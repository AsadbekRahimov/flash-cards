<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Engagement\DTO\MonthlyPrize;
use App\Domain\Engagement\DTO\MonthlyRatingOverview;
use App\Domain\Engagement\DTO\MonthlyStanding;
use App\Models\Learner;
use Illuminate\Http\Request;

/**
 * GET /api/twa/league/month (docs/04 A.19). Of other learners only a first name (may be null), the points and the active
 * days leave the server — no ids, usernames or Telegram data. The prize titles come in the interface locale.
 *
 * @property MonthlyRatingOverview $resource
 */
final class MonthlyRatingResource extends TwaResource
{
    /**
     * @return array{
     *   enabled: bool,
     *   opted_out: bool,
     *   month: array{starts_on: string, ends_on: string, closes_at: string, days_left: int, status: string|null, participants: int},
     *   rules: array{daily_cap: int, min_active_days: int, min_account_days: int, places: int, top: int},
     *   prizes: list<array{place: int, title: string}>,
     *   top: list<array{rank: int, name: string|null, points: int, active_days: int, is_me: bool}>,
     *   me: array{rank: int|null, points: int, active_days: int, participant: bool, eligible: bool, missing: array{active_days: int, account_days: int}, not_counted_reason: 'opted_out'|'blocked'|null}|null,
     *   last_month: array{starts_on: string, ends_on: string, status: string, winners: list<array{place: int, name: string|null, points: int}>, me: array{rank: int, points: int}|null}|null
     * }
     */
    public function toArray(Request $request): array
    {
        $overview = $this->resource;
        $locale = (string) app()->getLocale();
        $myId = $this->learnerId($request);

        return [
            'enabled' => $overview->enabled,
            'opted_out' => $overview->optedOut,
            'month' => [
                'starts_on' => $overview->startsOn,
                'ends_on' => $overview->endsOn,
                'closes_at' => $overview->closesAt,
                'days_left' => $overview->daysLeft,
                'status' => $overview->month?->status,
                'participants' => $overview->participantsCount,
            ],
            'rules' => $overview->rules,
            'prizes' => array_map(
                static fn (MonthlyPrize $prize): array => ['place' => $prize->place, 'title' => $prize->title($locale)],
                $overview->prizes,
            ),
            'top' => array_map(
                static fn (MonthlyStanding $row): array => [
                    'rank' => $row->rank,
                    'name' => $row->name,
                    'points' => $row->points,
                    'active_days' => $row->activeDays,
                    'is_me' => $row->learnerId === $myId,
                ],
                $overview->top,
            ),
            'me' => $overview->me?->toArray(),
            'last_month' => $overview->lastMonth === null ? null : [
                'starts_on' => $overview->lastMonth->startsOn,
                'ends_on' => $overview->lastMonth->endsOn,
                'status' => $overview->lastMonth->status,
                'winners' => $overview->lastMonth->winners,
                'me' => $overview->lastMonth->me,
            ],
        ];
    }

    private function learnerId(Request $request): int
    {
        $learner = $request->attributes->get('learner');

        return $learner instanceof Learner ? $learner->id : 0;
    }
}
