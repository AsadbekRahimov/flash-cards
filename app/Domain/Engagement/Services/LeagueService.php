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
use App\Jobs\SendLeagueResultJob;
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
 *                 outcome of their last closed week (or 0); the group is the first one of the tier
 *                 with a free seat, taken under `FOR UPDATE SKIP LOCKED` on PostgreSQL so parallel
 *                 placements spread over groups instead of queueing; when every group is full or
 *                 busy, creators serialise on an advisory lock and re-check with a waiting
 *                 `FOR UPDATE` before opening a new group. The unique `(week, learner)` makes a
 *                 repeated or parallel placement of one learner a no-op;
 *   standings   — ONE aggregate query over `learner_daily_stats` for the dates of the week with
 *                 the weights of `config('leagues.points')`, cached `standings_cache_seconds`
 *                 (`league:standings:{group}`); a closed week reads the final columns instead;
 *   closeWeek   — one transaction under the week row lock: every group's final points / ranks /
 *                 results, then `status = closed`. A closed week returns at once, so a repeated run
 *                 (two schedulers, a retry) changes nothing and sends nothing twice. The result DMs
 *                 are queued after the commit ({@see SendLeagueResultJob}, bot-wide send budget).
 *
 * Nothing here runs inside the review transaction: `place` is called by DailyStatsService in a
 * `DB::afterCommit` hook and locks only `league_*` rows (docs/13 §3 rule 5). Two learners are
 * never locked in one transaction (nothing of `learners` is locked at all).
 */
final class LeagueService
{
    public const OPT_OUT_META = 'leagues_opt_out';

    public const CACHE_PREFIX = 'league:standings:';

    public function __construct(private readonly ProductEventRecorder $events) {}

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
     * the membership, or null when the learner is not placed: leagues off, opted out, blocked.
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

        $tier = $this->tierFor($learner);
        $capacity = max(1, (int) config('leagues.capacity', 30));

        try {
            /** @var LeagueMember $member */
            $member = DB::transaction(function () use ($learner, $week, $tier, $capacity, $now): LeagueMember {
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

    /** The learner's most recent closed membership (the one that decides the next tier), if any. */
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

    /** Tier of the next placement: where the last closed week sent the learner, bounded to the configured tiers; 0 for a newcomer. */
    public function tierFor(Learner $learner): int
    {
        $last = $this->lastResult($learner);

        return $last === null ? 0 : max(0, min($this->topTier(), $last->tierAfter()));
    }

    /**
     * The standings of a group, best first (docs/13 §2.4). Cached for `standings_cache_seconds`
     * while the week is open; a closed week's standings are its final columns (no cache needed).
     *
     * @return Collection<int, LeagueStanding>
     */
    public function standings(LeagueGroup $group, CarbonImmutable $now): Collection
    {
        $week = $group->week;
        if ($week === null) {
            return new Collection;
        }

        if ($week->isClosed()) {
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
     * Closes a week: final points / ranks / results of every group, `status = closed`, then the
     * result DMs. Idempotent by status — a closed week returns false and nothing is sent again.
     * The whole write is one transaction under the week row lock, so a crash leaves the week
     * open for the next run and two runs cannot interleave.
     *
     * @return bool whether this call closed the week
     */
    public function closeWeek(LeagueWeek $week, CarbonImmutable $now): bool
    {
        $memberIds = [];
        $groupsClosed = 0;

        $closed = DB::transaction(function () use ($week, $now, &$memberIds, &$groupsClosed): bool {
            /** @var LeagueWeek|null $locked */
            $locked = LeagueWeek::query()->whereKey($week->id)->lockForUpdate()->first();
            if ($locked === null || $locked->isClosed()) {
                return false;
            }

            $locked->forceFill(['status' => LeagueWeekStatus::Closing->value])->save();

            /** @var EloquentCollection<int, LeagueGroup> $groups */
            $groups = LeagueGroup::query()->where('league_week_id', $locked->id)->orderBy('id')->get();
            foreach ($groups as $group) {
                foreach ($this->outcomes($group, $this->computeStandings($group, $locked)) as $memberId => [$standing, $result]) {
                    LeagueMember::query()->whereKey($memberId)->update([
                        'final_points' => $standing->points,
                        'final_rank' => $standing->rank,
                        'result' => $result->value,
                        'updated_at' => $now,
                    ]);
                    $memberIds[] = $memberId;
                }
                $groupsClosed++;
            }

            $locked->forceFill(['status' => LeagueWeekStatus::Closed->value, 'closed_at' => $now])->save();
            $week->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if (! $closed) {
            return false;
        }

        $this->events->record(ProductEventName::LeagueWeekClosed, [
            'week_id' => $week->id,
            'starts_on' => $week->starts_on->toDateString(),
            'groups' => $groupsClosed,
            'members' => count($memberIds),
        ]);

        foreach ($memberIds as $memberId) {
            SendLeagueResultJob::dispatch($memberId);
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
     * The group of the tier with a free seat, locked for this transaction, or a new one.
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
