<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Cup\Enums\CupStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The numbers of the pilot of the cup (docs/33 §8, K9; `php artisan cups:pilot-report --since=`): the cups by status, the participation
 * and the funnel of the invitation, who played to the end of a round, who came back on day 3, the groups that published a second cup.
 * Every block is ONE grouped statement (the second cup - two), whatever the number of cups: the cups of the period are a few thousand at
 * most (no index on `created_at` is needed for them); everything below is reached from them through the indexes of the core (the unique
 * `(cup_id, learner_id)`, `cup_day_scores (cup_id)`, the unique `(cup_id, day)` → the unique `(cup_day_id, participant_id)`), the
 * events through `product_events (name, occurred_at)`. Read only.
 */
final class CupPilotReport
{
    /** A group "came back" when it published its next cup within this many days after the finish (docs/33 §8). */
    public const SECOND_CUP_DAYS = 14;

    /**
     * @return array{
     *   since: string,
     *   cups: array<string, int>,
     *   participants: array{joined: int, played: int, left: int},
     *   invitation: array{viewers: int, joined: int},
     *   rounds: array{started: int, completed: int, expired: int, active: int, avg_points: float},
     *   return_day3: array{early: int, returned: int},
     *   second_cup: array{finished_groups: int, again: int, window_open: int}
     * }
     */
    public function report(CarbonImmutable $since, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return [
            'since' => $since->toIso8601String(),
            'cups' => $this->cups($since),
            'participants' => $this->participants($since),
            'invitation' => $this->invitation($since),
            'rounds' => $this->rounds($since),
            'return_day3' => $this->returnOnDayThree($since, $now),
            'second_cup' => $this->secondCup($since, $now),
        ];
    }

    /** @return array<string, int> status => cups created in the period */
    private function cups(CarbonImmutable $since): array
    {
        $counts = array_fill_keys(array_map(static fn (CupStatus $s): string => $s->value, CupStatus::cases()), 0);
        foreach (DB::table('cups')->where('created_at', '>=', $since)->groupBy('status')->selectRaw('status, count(*) as n')->get() as $row) {
            $counts[(string) $row->status] = (int) $row->n;
        }

        return $counts;
    }

    /** @return array{joined: int, played: int, left: int} the participants of the cups of the period: all, with a round played, gone from the chat */
    private function participants(CarbonImmutable $since): array
    {
        $row = DB::table('cup_participants as cp')
            ->join('cups as c', 'c.id', '=', 'cp.cup_id')
            ->where('c.created_at', '>=', $since)
            ->selectRaw('count(*) as joined')
            ->selectRaw('coalesce(sum(case when cp.days_played > 0 then 1 else 0 end), 0) as played')
            ->selectRaw("coalesce(sum(case when cp.status = 'left' then 1 else 0 end), 0) as gone")
            ->first();

        return ['joined' => (int) ($row->joined ?? 0), 'played' => (int) ($row->played ?? 0), 'left' => (int) ($row->gone ?? 0)];
    }

    /** @return array{viewers: int, joined: int} unique learners who opened an invitation in the period, and how many of them joined a cup */
    private function invitation(CarbonImmutable $since): array
    {
        $perLearner = DB::table('product_events')
            ->whereIn('name', [ProductEventName::CupInviteViewed->value, ProductEventName::CupJoined->value])
            ->where('occurred_at', '>=', $since)
            ->whereNotNull('learner_id')
            ->groupBy('learner_id')
            ->select('learner_id')
            ->selectRaw('max(case when name = ? then 1 else 0 end) as viewed', [ProductEventName::CupInviteViewed->value])
            ->selectRaw('max(case when name = ? then 1 else 0 end) as joined', [ProductEventName::CupJoined->value]);

        $row = DB::query()->fromSub($perLearner, 'l')
            ->where('l.viewed', 1)
            ->selectRaw('count(*) as viewers, coalesce(sum(l.joined), 0) as joined')
            ->first();

        return ['viewers' => (int) ($row->viewers ?? 0), 'joined' => (int) ($row->joined ?? 0)];
    }

    /** @return array{started: int, completed: int, expired: int, active: int, avg_points: float} the attempts of the rounds of the cups of the period */
    private function rounds(CarbonImmutable $since): array
    {
        $row = DB::table('cups as c')
            ->join('cup_days as d', 'd.cup_id', '=', 'c.id')
            ->join('cup_round_attempts as a', 'a.cup_day_id', '=', 'd.id')
            ->where('c.created_at', '>=', $since)
            ->selectRaw('count(*) as started')
            ->selectRaw("coalesce(sum(case when a.finish_reason = 'completed' then 1 else 0 end), 0) as completed")
            ->selectRaw("coalesce(sum(case when a.finish_reason = 'expired' then 1 else 0 end), 0) as expired")
            ->selectRaw("coalesce(sum(case when a.status = 'active' then 1 else 0 end), 0) as active")
            ->selectRaw('coalesce(avg(a.points), 0) as avg_points')
            ->first();

        return [
            'started' => (int) ($row->started ?? 0),
            'completed' => (int) ($row->completed ?? 0),
            'expired' => (int) ($row->expired ?? 0),
            'active' => (int) ($row->active ?? 0),
            'avg_points' => round((float) ($row->avg_points ?? 0), 2),
        ];
    }

    /**
     * Of the participants who played on day 1 or 2 of a cup that has reached its day 3 - how many played on a day ≥ 3 (a played day is a
     * row of `cup_day_scores`: the start of the round writes it).
     *
     * @return array{early: int, returned: int}
     */
    private function returnOnDayThree(CarbonImmutable $since, CarbonImmutable $now): array
    {
        $perParticipant = DB::table('cup_day_scores as s')
            ->join('cups as c', 'c.id', '=', 's.cup_id')
            ->where('c.created_at', '>=', $since)
            ->whereIn('c.status', [CupStatus::Running->value, CupStatus::Finished->value])
            ->where('c.starts_at', '<=', $now->subDays(2))
            ->groupBy('s.participant_id')
            ->select('s.participant_id')
            ->selectRaw('max(case when s.day <= 2 then 1 else 0 end) as early')
            ->selectRaw('max(case when s.day >= 3 then 1 else 0 end) as late');

        $row = DB::query()->fromSub($perParticipant, 'p')
            ->where('p.early', 1)
            ->selectRaw('count(*) as early, coalesce(sum(p.late), 0) as returned')
            ->first();

        return ['early' => (int) ($row->early ?? 0), 'returned' => (int) ($row->returned ?? 0)];
    }

    /**
     * The groups with a cup finished in the period, and of them those that published a next cup within {@see SECOND_CUP_DAYS} after its
     * finish (`window_open`: the window of the latest finish has not closed yet - the number may still grow). Two statements: the
     * finishes, then the publications of the same groups (index `(telegram_group_id, created_at)`); matched in memory.
     *
     * @return array{finished_groups: int, again: int, window_open: int}
     */
    private function secondCup(CarbonImmutable $since, CarbonImmutable $now): array
    {
        $finishes = DB::table('cups')
            ->where('status', CupStatus::Finished->value)
            ->where('finished_at', '>=', $since)
            ->groupBy('telegram_group_id')
            ->selectRaw('telegram_group_id, min(finished_at) as first_finish, max(finished_at) as last_finish')
            ->get();
        if ($finishes->isEmpty()) {
            return ['finished_groups' => 0, 'again' => 0, 'window_open' => 0];
        }

        $published = [];
        DB::table('cups')
            ->whereIn('telegram_group_id', $finishes->pluck('telegram_group_id')->all())
            ->whereNotNull('confirmed_at')
            ->where('confirmed_at', '>=', $since)
            ->select(['telegram_group_id', 'confirmed_at'])
            ->orderBy('id')
            ->each(function (object $row) use (&$published): void {
                $published[(int) $row->telegram_group_id][] = CarbonImmutable::parse((string) $row->confirmed_at);
            }, 500);

        $again = 0;
        $open = 0;
        foreach ($finishes as $group) {
            $first = CarbonImmutable::parse((string) $group->first_finish);
            $last = CarbonImmutable::parse((string) $group->last_finish);
            $came = false;
            foreach ($published[(int) $group->telegram_group_id] ?? [] as $at) {
                if ($at->gte($first) && $at->lte($last->addDays(self::SECOND_CUP_DAYS))) {
                    $came = true;
                }
            }
            $again += $came ? 1 : 0;
            $open += ! $came && $last->addDays(self::SECOND_CUP_DAYS)->gt($now) ? 1 : 0;
        }

        return ['finished_groups' => $finishes->count(), 'again' => $again, 'window_open' => $open];
    }
}
