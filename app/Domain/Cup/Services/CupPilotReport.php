<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Cup\Enums\CupStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The numbers of the pilot of the cup (docs/33 §8, K9; `php artisan cups:pilot-report --since=`): the cups by status, the participation
 * and the funnel of the invitation, who played to the end of a round, who came back on day 3, the groups that published a second cup.
 * A fixed number of grouped statements whatever the number of cups (7: the cups of the period, then one per block, the second cup - two).
 *
 * The cups of the period are read first (`cups` is small - thousands of rows) and every block below goes BY THEIR IDS through the indexes
 * of the core: the unique `(cup_id, learner_id)` of the participants, `cup_day_scores (cup_id)`, the unique `(cup_id, day)` of the days
 * and the unique `(cup_day_id, participant_id)` of the attempts; the events through `product_events (name, occurred_at)`. The literal
 * ids give PostgreSQL the true size of the period: a pilot of tens of cups is read by index, never by a scan of the big tables (with a
 * join on `cups.created_at` it estimated the heap of every cup as random and scanned all the participants). A period of more than
 * {@see MAX_IDS} cups goes by a subquery instead (the parameters of one statement are limited). Read only.
 */
final class CupPilotReport
{
    /** A group "came back" when it published its next cup within this many days after the finish (docs/33 §8). */
    public const SECOND_CUP_DAYS = 14;

    /** Above this many cups the blocks take the cups of the period by a subquery instead of the list of ids. */
    private const MAX_IDS = 10_000;

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

        $counts = array_fill_keys(array_map(static fn (CupStatus $s): string => $s->value, CupStatus::cases()), 0);
        $all = [];
        $reachedDay3 = [];
        $dayThree = $now->subDays(2);
        foreach (DB::table('cups')->where('created_at', '>=', $since)->get(['id', 'status', 'starts_at']) as $cup) {
            $status = (string) $cup->status;
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $all[] = (int) $cup->id;
            if (in_array($status, [CupStatus::Running->value, CupStatus::Finished->value], true)
                && $cup->starts_at !== null && CarbonImmutable::parse((string) $cup->starts_at)->lte($dayThree)) {
                $reachedDay3[] = (int) $cup->id;
            }
        }

        return [
            'since' => $since->toIso8601String(),
            'cups' => $counts,
            'participants' => $this->participants($this->scope($all, $since)),
            'invitation' => $this->invitation($since),
            'rounds' => $this->rounds($this->scope($all, $since)),
            'return_day3' => $this->returnOnDayThree($this->scope($reachedDay3, $since, $dayThree)),
            'second_cup' => $this->secondCup($since, $now),
        ];
    }

    /**
     * Narrows a statement to the cups of the period: the literal ids, or (above {@see MAX_IDS}) the same choice as a subquery.
     *
     * @param  list<int>  $ids
     * @return \Closure(Builder, string): Builder
     */
    private function scope(array $ids, CarbonImmutable $since, ?CarbonImmutable $startedBy = null): \Closure
    {
        if (count($ids) <= self::MAX_IDS) {
            return static fn (Builder $query, string $column): Builder => $query->whereIn($column, $ids);
        }

        $subquery = DB::table('cups')->select('id')->where('created_at', '>=', $since);
        if ($startedBy !== null) {
            $subquery->whereIn('status', [CupStatus::Running->value, CupStatus::Finished->value])->where('starts_at', '<=', $startedBy);
        }

        return static fn (Builder $query, string $column): Builder => $query->whereIn($column, $subquery);
    }

    /**
     * The participants of the cups of the period: all, with a round played, gone from the chat.
     *
     * @param  \Closure(Builder, string): Builder  $scope
     * @return array{joined: int, played: int, left: int}
     */
    private function participants(\Closure $scope): array
    {
        $row = $scope(DB::table('cup_participants'), 'cup_id')
            ->selectRaw('count(*) as joined')
            ->selectRaw('coalesce(sum(case when days_played > 0 then 1 else 0 end), 0) as played')
            ->selectRaw("coalesce(sum(case when status = 'left' then 1 else 0 end), 0) as gone")
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

    /**
     * The attempts of the rounds of the cups of the period: through their days (unique `(cup_id, day)`), then the unique
     * `(cup_day_id, participant_id)` of the attempts - `cup_round_attempts.cup_id` has no index.
     *
     * @param  \Closure(Builder, string): Builder  $scope
     * @return array{started: int, completed: int, expired: int, active: int, avg_points: float}
     */
    private function rounds(\Closure $scope): array
    {
        $row = $scope(DB::table('cup_days as d')->join('cup_round_attempts as a', 'a.cup_day_id', '=', 'd.id'), 'd.cup_id')
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
     * @param  \Closure(Builder, string): Builder  $scope  the cups of the period that run or finished and have reached day 3
     * @return array{early: int, returned: int}
     */
    private function returnOnDayThree(\Closure $scope): array
    {
        $perParticipant = $scope(DB::table('cup_day_scores'), 'cup_id')
            ->groupBy('participant_id')
            ->select('participant_id')
            ->selectRaw('max(case when day <= 2 then 1 else 0 end) as early')
            ->selectRaw('max(case when day >= 3 then 1 else 0 end) as late');

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
        foreach (DB::table('cups')
            ->whereIn('telegram_group_id', $finishes->pluck('telegram_group_id')->all())
            ->whereNotNull('confirmed_at')
            ->where('confirmed_at', '>=', $since)
            ->get(['telegram_group_id', 'confirmed_at']) as $row) {
            $published[(int) $row->telegram_group_id][] = CarbonImmutable::parse((string) $row->confirmed_at);
        }

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
