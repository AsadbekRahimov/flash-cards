<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Engagement\DTO\LeagueMembershipView;
use App\Domain\Engagement\DTO\LeagueOverview;
use App\Domain\Engagement\DTO\LeagueStanding;
use App\Domain\Engagement\DTO\TodayLeague;
use App\Domain\Engagement\Enums\LeagueResult;
use App\Domain\Engagement\Enums\LeagueWeekStatus;
use App\Domain\Engagement\Exceptions\LeagueAlreadyPlaced;
use App\Domain\Engagement\Exceptions\LeagueWeekNotOpen;
use App\Jobs\SendLeagueResultsJob;
use App\Models\LeagueGroup;
use App\Models\LeagueMember;
use App\Models\LeagueWeek;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Weekly leagues (docs/13 §1.5, §2.4, §7.3).
 *
 *   currentWeek — the `league_weeks` row of the Monday–Sunday week that contains `$now` in the
 *                 league time zone (one boundary for everybody), created on first use;
 *   place       — joins the learner to a group of their tier in the current week: the tier is the
 *                 outcome of their last membership (or 0); the group is the first one of the tier
 *                 with a free seat, taken under `FOR UPDATE SKIP LOCKED` on PostgreSQL so parallel
 *                 placements spread over groups instead of queueing; when every group is full or
 *                 busy, creators serialise on an advisory lock and re-check with a waiting
 *                 `FOR UPDATE` before opening a new group. The unique `(week, learner)` makes a
 *                 repeated or parallel placement of one learner a no-op. A placement NEVER closes a
 *                 week: when the learner's last group was not finalised yet (Monday before the close,
 *                 a missed scheduler run) it finalises that one group ({@see closeGroup}) and no other;
 *   standings   — ONE aggregate query over `learner_daily_stats` for the dates of the week with
 *                 the weights of `config('leagues.points')`, cached `standings_cache_seconds`
 *                 (`league:standings:{group}`); a finalised group reads the final columns instead;
 *   closeGroup  — the unit of closing: one short transaction under the group row lock writes the
 *                 final points / ranks / results of the whole group in ONE statement and stamps
 *                 `league_groups.closed_at`; idempotent by that stamp;
 *   closeWeek   — `open → closing` (the barrier: from then on nobody is seated in the week), every
 *                 group not finalised yet in its own transaction, `closing → closed` under the week
 *                 row lock, and — only in the one call that made that last step — the event, the
 *                 result DMs in batches ({@see SendLeagueResultsJob}) and the winners' achievements.
 *                 Resumable after a crash, nothing is written or sent twice by a second closer.
 *
 * Locks. A placement holds the week row `FOR SHARE` while it takes a seat and the group row
 * `FOR UPDATE` (that order); the closer's barrier and final step hold the week row `FOR UPDATE`
 * alone for milliseconds, its group steps hold one group row `FOR UPDATE` alone. So the barrier
 * waits for in-flight placements (never the other way round for longer than one group's write),
 * a seat is never taken in a `closing` week, and no cycle exists. Nothing here runs inside the
 * review transaction: `place` is called by DailyStatsService in a `DB::afterCommit` hook and locks
 * only `league_*` rows (docs/13 §3 rule 5); nothing of `learners` is locked at all.
 */
final class LeagueService
{
    public const OPT_OUT_META = 'leagues_opt_out';

    public const CACHE_PREFIX = 'league:standings:';

    public function __construct(
        private readonly ProductEventRecorder $events,
        private readonly AchievementService $achievements,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('leagues.enabled', true);
    }

    public function timezone(): DateTimeZone
    {
        try {
            return new DateTimeZone((string) config('leagues.timezone', 'Asia/Tashkent'));
        } catch (Throwable) {
            return new DateTimeZone('Asia/Tashkent');
        }
    }

    /** Monday 00:00 (league zone) of the week that contains `$now`. */
    public function weekStart(CarbonImmutable $now): CarbonImmutable
    {
        return $now->setTimezone($this->timezone())->startOfWeek(CarbonInterface::MONDAY);
    }

    /** The week ended: `$now` is in a later week (Monday 00:00 of the next week in the league zone has passed). */
    public function hasEnded(LeagueWeek $week, CarbonImmutable $now): bool
    {
        return $week->starts_on->toDateString() < $this->weekStart($now)->toDateString();
    }

    /** The row of the current week, created when missing (a parallel creator's row is reused). */
    public function currentWeek(CarbonImmutable $now): LeagueWeek
    {
        $monday = $this->weekStart($now);
        $existing = $this->findWeek($now);
        if ($existing !== null) {
            return $existing;
        }

        try {
            // Savepoint: a lost creation race must not poison an outer (PostgreSQL) transaction.
            /** @var LeagueWeek $week */
            $week = DB::transaction(static fn (): LeagueWeek => LeagueWeek::query()->create([
                'starts_on' => $monday->toDateString(),
                'ends_on' => $monday->addDays(6)->toDateString(),
                'status' => LeagueWeekStatus::Open->value,
            ]));

            return $week;
        } catch (UniqueConstraintViolationException) {
            $week = $this->findWeek($now);
            if ($week === null) {
                throw new RuntimeException('League week vanished after a unique violation.');
            }

            return $week;
        }
    }

    /** The row of the week that contains `$now`, or null when nobody was placed in it yet. */
    public function findWeek(CarbonImmutable $now): ?LeagueWeek
    {
        /** @var LeagueWeek|null $week */
        $week = LeagueWeek::query()->where('starts_on', $this->storedDate($this->weekStart($now)->toDateString()))->first();

        return $week;
    }

    public function isOptedOut(Learner $learner): bool
    {
        return (bool) (($learner->meta ?? [])[self::OPT_OUT_META] ?? false);
    }

    /**
     * Joins the learner to the current week (first activity of the week; after commit). Returns
     * the membership, or null when the learner is not placed: leagues off, opted out, blocked, or
     * the week is already being closed (a request that started before Monday 00:00).
     */
    public function place(Learner $learner, CarbonImmutable $now): ?LeagueMember
    {
        if (! $this->enabled() || $this->isOptedOut($learner) || $learner->blocked_at !== null) {
            return null;
        }

        $week = $this->currentWeek($now);
        $existing = $this->membership($learner, $week);
        if ($existing !== null) {
            return $existing;
        }

        // The tier is the verdict of the learner's own last group. Closing the week is the scheduler's job and
        // never happens here: if that one group was not finalised yet, it is — alone, in a few milliseconds.
        $tier = $this->tierFor($learner, $week, $now);
        $capacity = max(1, (int) config('leagues.capacity', 30));

        try {
            /** @var LeagueMember $member */
            $member = DB::transaction(function () use ($learner, $week, $tier, $capacity, $now): LeagueMember {
                // The barrier against the closer: the week row is held FOR SHARE for the rest of this transaction,
                // so `open → closing` (an UPDATE, FOR NO KEY UPDATE) waits for us or happened before — never under us.
                /** @var LeagueWeek|null $open */
                $open = LeagueWeek::query()->whereKey($week->id)->sharedLock()->first();
                if ($open === null || $open->status !== LeagueWeekStatus::Open->value) {
                    throw new LeagueWeekNotOpen;
                }

                $group = $this->groupWithSeat($week, $tier, $capacity);

                try {
                    // Savepoint: the unique violation of a parallel placement must not poison the transaction.
                    /** @var LeagueMember $member */
                    $member = DB::transaction(static fn (): LeagueMember => LeagueMember::query()->create([
                        'league_group_id' => $group->id,
                        'league_week_id' => $week->id,
                        'learner_id' => $learner->id,
                        'tier_before' => $tier,
                    ]));
                } catch (UniqueConstraintViolationException) {
                    // Roll the whole placement back (including a group opened for nothing).
                    throw new LeagueAlreadyPlaced;
                }

                $seated = LeagueGroup::query()
                    ->whereKey($group->id)
                    ->whereColumn('members_count', '<', 'capacity')
                    ->increment('members_count', 1, ['updated_at' => $now]);
                if ($seated !== 1) {
                    throw new RuntimeException("League group {$group->id} is full although it was picked with a free seat.");
                }

                return $member;
            });
        } catch (LeagueAlreadyPlaced) {
            return $this->membership($learner, $week);
        } catch (LeagueWeekNotOpen) {
            return null;
        }

        $this->events->record(
            ProductEventName::LeaguePlaced,
            ['week_id' => $week->id, 'group_id' => $member->league_group_id, 'tier' => $tier],
            learner: $learner,
        );

        return $member;
    }

    public function membership(Learner $learner, LeagueWeek $week): ?LeagueMember
    {
        /** @var LeagueMember|null $member */
        $member = LeagueMember::query()
            ->where('league_week_id', $week->id)
            ->where('learner_id', $learner->id)
            ->first();

        return $member;
    }

    /** The learner's most recent closed membership (result known), if any — what the overview shows as "last week". */
    public function lastResult(Learner $learner): ?LeagueMember
    {
        /** @var LeagueMember|null $member */
        $member = LeagueMember::query()
            ->where('learner_id', $learner->id)
            ->whereNotNull('result')
            ->orderByDesc('league_week_id')
            ->first();

        return $member;
    }

    /**
     * Tier of a placement into `$week`: where the learner's most recent earlier membership sent them
     * (bounded to the configured tiers); 0 for a newcomer. A skipped week does not reset the tier.
     *
     * When that membership's group was not finalised yet — its week ended, but the close has not reached
     * the group (Monday right after midnight, a missed scheduler run) — the group, and only that group,
     * is finalised here first, so the promotion / demotion is never lost and the verdict is exactly the
     * one the week close will report. No week is closed, nothing waits for the closer longer than one
     * group's write.
     */
    public function tierFor(Learner $learner, LeagueWeek $week, CarbonImmutable $now): int
    {
        $last = $this->lastMembershipBefore($learner, $week);
        if ($last === null) {
            return 0;
        }

        if ($last->result === null) {
            $group = $last->group;
            if ($group !== null) {
                $this->closeGroup($group, $now);
                $last->refresh();
            }
        }

        // No verdict (the group's week has not ended — only possible with hand-made rows): the tier is kept.
        $next = $last->result === null ? $last->tier_before : $last->tierAfter();

        return max(0, min($this->topTier(), $next));
    }

    /**
     * The standings of a group, best first (docs/13 §2.4). Cached for `standings_cache_seconds`
     * while the group is open; a finalised group's standings are its final columns (no cache needed).
     * The week's status is read fresh; the group's own stamp is taken from the given instance — pass a
     * freshly loaded group (as the overview does) to see a group finalised early in a week still open.
     *
     * @return Collection<int, LeagueStanding>
     */
    public function standings(LeagueGroup $group, CarbonImmutable $now): Collection
    {
        $week = $group->week;
        if ($week === null) {
            return new Collection;
        }

        if ($week->isClosed() || $group->isClosed()) {
            return $this->finalStandings($group);
        }

        $ttl = max(1, (int) config('leagues.standings_cache_seconds', 60));

        /** @var list<LeagueStanding> $rows */
        $rows = Cache::remember(self::CACHE_PREFIX.$group->id, $ttl, fn (): array => $this->computeStandings($group, $week));

        return collect($rows);
    }

    /** The "Today" line: the learner's place this week, or null when not placed (or leagues off). */
    public function todayLine(Learner $learner, CarbonImmutable $now): ?TodayLeague
    {
        if (! $this->enabled()) {
            return null;
        }

        $week = $this->findWeek($now);
        $member = $week === null ? null : $this->membership($learner, $week);
        $group = $member?->group;
        if ($member === null || $group === null) {
            return null;
        }

        $standings = $this->standings($group, $now);
        $mine = $standings->first(static fn (LeagueStanding $s): bool => $s->memberId === $member->id);
        if ($mine === null) {
            return null;
        }

        return new TodayLeague($group->tier, $this->tierKey($group->tier), $mine->rank, $mine->points, $standings->count());
    }

    /** What `GET /api/twa/league` shows. */
    public function overview(Learner $learner, CarbonImmutable $now): LeagueOverview
    {
        $monday = $this->weekStart($now);
        $enabled = $this->enabled();
        $week = $enabled ? $this->findWeek($now) : null;
        $member = $week === null ? null : $this->membership($learner, $week);
        $group = $member?->group;

        return new LeagueOverview(
            enabled: $enabled,
            week: $week,
            weekStartsOn: $monday->toDateString(),
            weekEndsOn: $monday->addDays(6)->toDateString(),
            closesAt: $monday->addWeek()->utc()->toIso8601String(),
            capacity: max(1, (int) config('leagues.capacity', 30)),
            promote: $this->promoteCount(),
            demote: $this->demoteCount(),
            tiers: $this->tiers(),
            optedOut: $this->isOptedOut($learner),
            membership: $member === null || $group === null ? null : $this->membershipView($member, $group, $now),
            lastResult: $enabled ? $this->lastResult($learner) : null,
        );
    }

    /**
     * Finalises one group of an ended week in its own short transaction: the group row is locked, the
     * standings are computed once, every member's final points / rank / result are written in ONE
     * statement, `closed_at` is stamped. Idempotent by the stamp: a group already finalised (by the week
     * close, or by the placement of one of its members that needed the verdict early) is left as it is.
     * A group of a week that has not ended yet is refused. Only the group row is locked, for milliseconds.
     *
     * @return bool whether this call finalised the group
     */
    public function closeGroup(LeagueGroup $group, CarbonImmutable $now): bool
    {
        return DB::transaction(function () use ($group, $now): bool {
            /** @var LeagueGroup|null $locked */
            $locked = LeagueGroup::query()->whereKey($group->id)->lockForUpdate()->first();
            if ($locked === null || $locked->isClosed()) {
                return false;
            }

            $week = $locked->week;
            if ($week === null || ! $this->hasEnded($week, $now)) {
                return false;
            }

            $this->finalise($locked, $week, $now);
            $group->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * Closes an ended week — see the class comment for the steps. Idempotent and resumable: a second closer
     * (the 00:05 safety run, a retry, a parallel command) finds the groups stamped and the week closed and
     * writes or sends nothing; a crash leaves the week `closing` with some groups done, and the next run
     * finishes the rest. A week that has not ended yet is refused: nothing happens, false.
     *
     * @return bool whether this call closed the week (made the `closing → closed` step)
     */
    public function closeWeek(LeagueWeek $week, CarbonImmutable $now): bool
    {
        if (! $this->hasEnded($week, $now) || ! $this->beginClosing($week)) {
            return false;
        }

        do {
            $this->closeRemainingGroups($week, $now);
            $closed = $this->finishClosing($week, $now);
        } while ($closed === null);

        if (! $closed) {
            return false;
        }

        $this->events->record(ProductEventName::LeagueWeekClosed, [
            'week_id' => $week->id,
            'starts_on' => $week->starts_on->toDateString(),
            'groups' => LeagueGroup::query()->where('league_week_id', $week->id)->count(),
            'members' => LeagueMember::query()->where('league_week_id', $week->id)->count(),
        ]);

        $this->queueResultMessages($week, $now);

        // The winners of the groups get `league_top1` (docs/13 §7.4) — after the commit, once per learner
        // (the unique key), and a failure here never undoes the closed week.
        try {
            $this->achievements->onLeagueWeekClosed($this->winnerIds($week), $now);
        } catch (Throwable $e) {
            report($e);
        }

        return true;
    }

    /**
     * Closes every week that ended before the current one (the scheduler's Monday run; a missed
     * run is caught up by the next). Returns the number of weeks closed by this call.
     */
    public function closeDue(CarbonImmutable $now): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        /** @var EloquentCollection<int, LeagueWeek> $due */
        $due = LeagueWeek::query()
            ->where('status', '<>', LeagueWeekStatus::Closed->value)
            ->where('starts_on', '<', $this->storedDate($this->weekStart($now)->toDateString()))
            ->orderBy('starts_on')
            ->get();

        $closed = 0;
        foreach ($due as $week) {
            if ($this->closeWeek($week, $now)) {
                $closed++;
            }
        }

        return $closed;
    }

    /** @return list<string> */
    public function tiers(): array
    {
        $tiers = array_values(array_map('strval', (array) config('leagues.tiers', [])));

        return $tiers === [] ? ['bronze'] : $tiers;
    }

    public function tierKey(int $tier): string
    {
        $tiers = $this->tiers();

        return $tiers[max(0, min($this->topTier(), $tier))];
    }

    public function topTier(): int
    {
        return count($this->tiers()) - 1;
    }

    public function promoteCount(): int
    {
        return max(0, (int) config('leagues.promote', 5));
    }

    public function demoteCount(): int
    {
        return max(0, (int) config('leagues.demote', 5));
    }

    /**
     * The learner's most recent membership in a week that started before `$week` (by the week's date,
     * not by row ids), with or without a verdict.
     */
    private function lastMembershipBefore(Learner $learner, LeagueWeek $week): ?LeagueMember
    {
        /** @var LeagueMember|null $member */
        $member = LeagueMember::query()
            ->join('league_weeks', 'league_weeks.id', '=', 'league_members.league_week_id')
            ->where('league_members.learner_id', $learner->id)
            ->where('league_weeks.starts_on', '<', $this->storedDate($week->starts_on->toDateString()))
            ->orderByDesc('league_weeks.starts_on')
            ->select('league_members.*')
            ->first();

        return $member;
    }

    /**
     * The barrier: `open → closing` under the week row lock. From the commit on, no placement takes a seat
     * in the week (it reads the status under `FOR SHARE`), so the set of groups and members is frozen for
     * the rest of the close. A week already `closing` (a crashed run) passes; a closed one returns false.
     */
    private function beginClosing(LeagueWeek $week): bool
    {
        return DB::transaction(function () use ($week): bool {
            /** @var LeagueWeek|null $locked */
            $locked = LeagueWeek::query()->whereKey($week->id)->lockForUpdate()->first();
            if ($locked === null || $locked->isClosed()) {
                return false;
            }

            if ($locked->status === LeagueWeekStatus::Open->value) {
                $locked->forceFill(['status' => LeagueWeekStatus::Closing->value])->save();
            }
            $week->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * Finalises the groups of the week that are not stamped yet, one per transaction, oldest group first.
     * A group busy with another finaliser (a parallel closer, a placement needing its verdict) is waited
     * for and then found stamped — PostgreSQL re-checks `closed_at IS NULL` on the committed row and moves
     * on to the next one — so two closers split the work instead of repeating it.
     */
    private function closeRemainingGroups(LeagueWeek $week, CarbonImmutable $now): void
    {
        do {
            $done = DB::transaction(function () use ($week, $now): bool {
                /** @var LeagueGroup|null $group */
                $group = LeagueGroup::query()
                    ->where('league_week_id', $week->id)
                    ->whereNull('closed_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
                if ($group === null) {
                    return true;
                }

                $this->finalise($group, $week, $now);

                return false;
            });
        } while (! $done);
    }

    /**
     * `closing → closed` under the week row lock, once every group is stamped.
     *
     * @return bool|null true = closed by this call; false = somebody else closed it already; null = groups
     *                   are still open (cannot happen behind the barrier; the caller loops anyway)
     */
    private function finishClosing(LeagueWeek $week, CarbonImmutable $now): ?bool
    {
        return DB::transaction(function () use ($week, $now): ?bool {
            /** @var LeagueWeek|null $locked */
            $locked = LeagueWeek::query()->whereKey($week->id)->lockForUpdate()->first();
            if ($locked === null || $locked->isClosed()) {
                return false;
            }

            if (LeagueGroup::query()->where('league_week_id', $week->id)->whereNull('closed_at')->exists()) {
                return null;
            }

            $locked->forceFill(['status' => LeagueWeekStatus::Closed->value, 'closed_at' => $now])->save();
            $week->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * Inside the caller's transaction, with the group row locked: standings once, every member's final
     * columns in one statement, the stamp.
     */
    private function finalise(LeagueGroup $group, LeagueWeek $week, CarbonImmutable $now): void
    {
        $this->writeOutcomes($this->outcomes($group, $this->computeStandings($group, $week)), $now);
        $group->forceFill(['closed_at' => $now])->save();
    }

    /**
     * Final points / rank / result of a whole group in ONE statement, bindings only. PostgreSQL:
     * `UPDATE … FROM (VALUES …)` with typed parameters; sqlite (tests) has no typed VALUES list and
     * gets the equivalent CASE form.
     *
     * @param  array<int, array{0: LeagueStanding, 1: LeagueResult}>  $outcomes  keyed by member id
     */
    private function writeOutcomes(array $outcomes, CarbonImmutable $now): void
    {
        if ($outcomes === []) {
            return;
        }

        $stamp = (string) (new LeagueMember)->fromDateTime($now);

        if (DB::connection()->getDriverName() === 'pgsql') {
            $rows = [];
            $bindings = [$stamp];
            foreach ($outcomes as $memberId => [$standing, $result]) {
                $rows[] = '(?::bigint, ?::integer, ?::smallint, ?::varchar)';
                array_push($bindings, $memberId, $standing->points, $standing->rank, $result->value);
            }

            DB::update(
                'update league_members as lm set final_points = v.final_points, final_rank = v.final_rank, '
                .'result = v.final_result, updated_at = ? from (values '.implode(', ', $rows).') '
                .'as v (member_id, final_points, final_rank, final_result) where lm.id = v.member_id',
                $bindings,
            );

            return;
        }

        $branches = str_repeat(' when ? then ?', count($outcomes));
        $bindings = [];
        foreach (['points', 'rank', 'result'] as $column) {
            foreach ($outcomes as $memberId => [$standing, $result]) {
                array_push($bindings, $memberId, match ($column) {
                    'points' => $standing->points,
                    'rank' => $standing->rank,
                    'result' => $result->value,
                });
            }
        }
        $bindings[] = $stamp;
        $ids = array_keys($outcomes);
        array_push($bindings, ...$ids);

        DB::update(
            'update league_members set '
            ."final_points = case id{$branches} end, "
            ."final_rank = case id{$branches} end, "
            ."result = case id{$branches} end, "
            .'updated_at = ? where id in ('.implode(', ', array_fill(0, count($ids), '?')).')',
            $bindings,
        );
    }

    /**
     * Queues the result DMs of the week in batches of `notify_batch_size` members ({@see SendLeagueResultsJob}),
     * skipping those not worth a message: banned, DM closed, opted out, deleted (the sender checks the same
     * again at send time). One query over the week's members, no models. All batches share ONE deadline
     * (`leagues.notify_ttl_hours` from the close), which the remainders handed on by the jobs keep.
     */
    private function queueResultMessages(LeagueWeek $week, CarbonImmutable $closedAt): void
    {
        $rows = DB::table('league_members as lm')
            ->join('learners as l', 'l.id', '=', 'lm.learner_id')
            ->where('lm.league_week_id', $week->id)
            ->whereNull('l.deleted_at')
            ->whereNull('l.blocked_at')
            ->whereNull('l.dm_blocked_at')
            ->orderBy('lm.id')
            ->get(['lm.id', 'l.meta']);

        $memberIds = [];
        foreach ($rows as $row) {
            $meta = is_string($row->meta) ? json_decode($row->meta, true) : $row->meta;
            if (is_array($meta) && (bool) ($meta[self::OPT_OUT_META] ?? false)) {
                continue;
            }
            $memberIds[] = (int) $row->id;
        }

        $notAfter = SendLeagueResultsJob::deadline($closedAt)->getTimestamp();
        foreach (array_chunk($memberIds, max(1, (int) config('leagues.notify_batch_size', 100))) as $batch) {
            SendLeagueResultsJob::dispatch($batch, $notAfter);
        }
    }

    /**
     * Members who finished first in their group with at least one point (docs/13 §7.4: a win, not an empty group).
     *
     * @return list<int>
     */
    private function winnerIds(LeagueWeek $week): array
    {
        /** @var list<int> $ids */
        $ids = LeagueMember::query()
            ->where('league_week_id', $week->id)
            ->where('final_rank', 1)
            ->where('final_points', '>', 0)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
    }

    /**
     * The open group of the tier with a free seat, locked for this transaction, or a new one.
     *
     * PostgreSQL: `FOR UPDATE SKIP LOCKED` first (parallel placements take different groups, nobody
     * waits); when nothing is free or unlocked, an advisory lock per (week, tier) serialises the
     * would-be creators, and under it a plain `FOR UPDATE` waits for the busy group — PostgreSQL
     * re-evaluates `members_count < capacity` on the committed row — so a group is opened only when
     * every existing one is really full. No cycle: a transaction that holds a group row never asks
     * for the advisory lock. Other drivers (sqlite in tests) have no row locks and run serially.
     */
    private function groupWithSeat(LeagueWeek $week, int $tier, int $capacity): LeagueGroup
    {
        $candidates = static fn () => LeagueGroup::query()
            ->where('league_week_id', $week->id)
            ->where('tier', $tier)
            ->whereNull('closed_at')
            ->whereColumn('members_count', '<', 'capacity')
            ->orderBy('id');

        if (DB::connection()->getDriverName() === 'pgsql') {
            /** @var LeagueGroup|null $group */
            $group = $candidates()->lock('for update skip locked')->first();
            if ($group !== null) {
                return $group;
            }

            DB::statement('select pg_advisory_xact_lock(?::bigint)', [$week->id * 16 + $tier]);
        }

        /** @var LeagueGroup|null $group */
        $group = $candidates()->lockForUpdate()->first();
        if ($group !== null) {
            return $group;
        }

        /** @var LeagueGroup $created */
        $created = LeagueGroup::query()->create([
            'league_week_id' => $week->id,
            'tier' => $tier,
            'capacity' => $capacity,
            'members_count' => 0,
        ]);

        return $created;
    }

    /**
     * Fresh standings of an open group: one grouped query over the members, their names and the
     * `learner_daily_stats` rows of the week's dates.
     *
     * @return list<LeagueStanding>
     */
    private function computeStandings(LeagueGroup $group, LeagueWeek $week): array
    {
        $from = $this->storedDate($week->starts_on->toDateString());
        $to = $this->storedDate($week->ends_on->toDateString());
        $weights = $this->weights();

        $rows = DB::table('league_members as lm')
            ->leftJoin('learners as l', 'l.id', '=', 'lm.learner_id')
            ->leftJoin('learner_daily_stats as s', function (JoinClause $join) use ($from, $to): void {
                $join->on('s.learner_id', '=', 'lm.learner_id')
                    ->where('s.date', '>=', $from)
                    ->where('s.date', '<=', $to);
            })
            ->where('lm.league_group_id', $group->id)
            ->groupBy('lm.id', 'lm.learner_id', 'l.first_name', 'l.deleted_at')
            ->selectRaw(
                'lm.id as member_id, lm.learner_id, l.first_name, l.deleted_at, '
                .'coalesce(sum(s.reviews_count * ? + s.correct_count * ? + s.new_words_count * ? '
                .'+ case when s.goal_met_at is not null then ? else 0 end), 0) as points',
                [$weights['review'], $weights['correct'], $weights['new_word'], $weights['goal_met']],
            )
            ->orderByDesc('points')
            ->orderBy('lm.id')
            ->get();

        $standings = [];
        $rank = 0;
        foreach ($rows as $row) {
            $rank++;
            $deleted = $row->deleted_at ?? null;
            $standings[] = new LeagueStanding(
                memberId: (int) $row->member_id,
                learnerId: (int) $row->learner_id,
                name: $deleted === null && is_string($row->first_name) && $row->first_name !== '' ? $row->first_name : null,
                points: (int) $row->points,
                rank: $rank,
            );
        }

        return $standings;
    }

    /** @return Collection<int, LeagueStanding> */
    private function finalStandings(LeagueGroup $group): Collection
    {
        /** @var EloquentCollection<int, LeagueMember> $members */
        $members = LeagueMember::query()
            ->with('learner')
            ->where('league_group_id', $group->id)
            ->whereNotNull('final_rank')
            ->orderBy('final_rank')
            ->get();

        return $members->toBase()->map(static function (LeagueMember $m): LeagueStanding {
            $name = $m->learner?->first_name;

            return new LeagueStanding(
                memberId: $m->id,
                learnerId: $m->learner_id,
                name: $name === '' ? null : $name,
                points: (int) $m->final_points,
                rank: (int) $m->final_rank,
            );
        })->values();
    }

    /**
     * Results of a group at the close: top `promote` promoted (unless the tier is the top one),
     * bottom `demote` demoted (unless the tier is 0), the rest stayed. Promotion wins when a
     * small group overlaps both zones.
     *
     * @param  list<LeagueStanding>  $standings
     * @return array<int, array{0: LeagueStanding, 1: LeagueResult}> keyed by member id
     */
    private function outcomes(LeagueGroup $group, array $standings): array
    {
        $count = count($standings);
        $promote = $this->promoteCount();
        $demote = $this->demoteCount();
        $canPromote = $group->tier < $this->topTier();
        $canDemote = $group->tier > 0;

        $outcomes = [];
        foreach ($standings as $standing) {
            $outcomes[$standing->memberId] = [$standing, match (true) {
                $canPromote && $standing->rank <= $promote => LeagueResult::Promoted,
                $canDemote && $standing->rank > $count - $demote => LeagueResult::Demoted,
                default => LeagueResult::Stayed,
            }];
        }

        return $outcomes;
    }

    private function membershipView(LeagueMember $member, LeagueGroup $group, CarbonImmutable $now): ?LeagueMembershipView
    {
        $standings = $this->standings($group, $now);
        $mine = $standings->first(static fn (LeagueStanding $s): bool => $s->memberId === $member->id);
        if ($mine === null) {
            return null;
        }

        $count = $standings->count();
        $promote = $this->promoteCount();
        $demote = $this->demoteCount();
        $canPromote = $group->tier < $this->topTier();
        $canDemote = $group->tier > 0;
        /** @var list<LeagueStanding> $list */
        $list = $standings->values()->all();

        $inPromotion = $canPromote && $mine->rank <= $promote;
        $inDemotion = ! $inPromotion && $canDemote && $mine->rank > $count - $demote;

        $pointsToPromote = null;
        if ($canPromote && ! $inPromotion && $promote > 0 && isset($list[$promote - 1])) {
            $pointsToPromote = max(1, $list[$promote - 1]->points - $mine->points + 1);
        }

        $pointsToSafety = null;
        if ($inDemotion && isset($list[$count - $demote - 1])) {
            $pointsToSafety = max(1, $list[$count - $demote - 1]->points - $mine->points + 1);
        }

        return new LeagueMembershipView(
            tier: $group->tier,
            tierKey: $this->tierKey($group->tier),
            rank: $mine->rank,
            points: $mine->points,
            membersCount: $count,
            zone: $inPromotion ? 'promotion' : ($inDemotion ? 'demotion' : 'safe'),
            pointsToPromote: $pointsToPromote,
            pointsToSafety: $pointsToSafety,
            standings: $list,
        );
    }

    /** @return array{review: int, correct: int, new_word: int, goal_met: int} */
    private function weights(): array
    {
        return [
            'review' => (int) config('leagues.points.review', 1),
            'correct' => (int) config('leagues.points.correct', 1),
            'new_word' => (int) config('leagues.points.new_word', 2),
            'goal_met' => (int) config('leagues.points.goal_met', 10),
        ];
    }

    /** A `date` column as Eloquent stores it (sqlite compares text; see DailyStatsService). */
    private function storedDate(string $date): string
    {
        return (string) (new LeagueWeek)->fromDateTime($date);
    }
}
