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
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Exceptions\LeagueAlreadyPlaced;
use App\Domain\Engagement\Exceptions\LeagueWeekNotOpen;
use App\Domain\Engagement\Services\Scoring\ScoreRules;
use App\Domain\Engagement\Services\Scoring\WordScoreService;
use App\Domain\Engagement\Support\LeaguePoints;
use App\Domain\Learners\Services\ProEmojiService;
use App\Domain\Learners\Support\StoredDate;
use App\Domain\Operations\Support\PureCache;
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
 *                 the weights of `config('leagues.points')` plus the capped `game_points` of the game, cached
 *                 `standings_cache_seconds` (`league:standings:v2:{group}`, arrays of scalars); a finalised group reads the final columns instead;
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
 * review transaction: `place` is called by DailyStatsService (the first grade of a local day) and by
 * RunFinisher (a run that is the first activity of the day, docs/18 §12.6) in a `DB::afterCommit` hook and
 * locks only `league_*` rows (docs/13 §3 rule 5); nothing of `learners` is locked at all.
 *
 * @phpstan-import-type LeagueStandingArray from LeagueStanding
 */
final class LeagueService
{
    public const OPT_OUT_META = 'leagues_opt_out';

    /**
     * `v1` = arrays of scalars (the objects of the unversioned keys are never read, cache.serializable_classes = false);
     * `v2` = the points include `game_points` of the day rows (docs/18 §12.6, G2.10) — the same shape, another meaning.
     */
    public const CACHE_PREFIX = 'league:standings:v2:';

    /**
     * `league:member:v1:{learner}:{monday}` - the facts of the learner's placement in the week that starts on `monday`
     * (docs/19 K9): `{week_id, member_id, group_id, tier}`. Immutable for the week (a member never moves between groups,
     * the tier is fixed at placement), so the Today line needs no statement once it is there; the Monday in the key
     * keeps a new week from reading the old one. Written only when the learner IS placed: "not placed" is never cached
     * (the first grade of the week places, and a cached "no" could outlive it); it lives in the evicting store.
     */
    public const MEMBER_PREFIX = 'league:member:v1:';

    /**
     * `league:standings:v3:{group}` - the standings of a group of a v2 week (docs/32 §5.1, docs/19 §3 rule 3): the best days, the
     * tie-break facts and the competition ranks - another meaning of the same array, so another key. A v1 week keeps {@see CACHE_PREFIX}.
     */
    public const CACHE_PREFIX_V2 = 'league:standings:v3:';

    /**
     * `league:emoji:v1:group:{group}` - the emoji of Pro of the members of an open group (docs/31 §5а, docs/19 §3): `{learner_id: emoji}`
     * of the members with a live Pro and a choice, `[]` when nobody has one. A key of its own beside the standings (not inside them): the
     * Today line reads the standings and never pays for the emoji. The same TTL as the standings (`standings_cache_seconds`), so an
     * emoji of a Pro that has run out shows at most that long; a change of the learner's own choice drops the key at once
     * ({@see proEmojiChanged()}).
     */
    public const EMOJI_PREFIX = 'league:emoji:v1:group:';

    public function __construct(
        private readonly ProductEventRecorder $events,
        private readonly AchievementService $achievements,
        private readonly ScoreRules $scoreRules,
        private readonly ProEmojiService $proEmoji,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('leagues.enabled', true);
    }

    public function timezone(): DateTimeZone
    {
        return self::leagueZone();
    }

    /** The league zone of the configuration (`leagues.timezone`), Tashkent when it is not a zone. */
    public static function leagueZone(): DateTimeZone
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
            // The rules version is written once, here, and never changed (docs/32 §6.1): the week closes by it, and the learners' weeks
            // fixed by their first credit take it from this row (ScorePeriodService::insert).
            $rulesVersion = $this->scoreRules->versionFor($monday->toDateString());
            /** @var LeagueWeek $week */
            $week = DB::transaction(static fn (): LeagueWeek => LeagueWeek::query()->create([
                'starts_on' => $monday->toDateString(),
                'ends_on' => $monday->addDays(6)->toDateString(),
                'status' => LeagueWeekStatus::Open->value,
                'rules_version' => $rulesVersion,
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
        $week = LeagueWeek::query()->where('starts_on', StoredDate::of($this->weekStart($now)->toDateString()))->first();

        return $week;
    }

    public function isOptedOut(Learner $learner): bool
    {
        return (bool) (($learner->meta ?? [])[self::OPT_OUT_META] ?? false);
    }

    /**
     * Joins the learner to the current week (after commit). Returns the membership, or null when the learner is not placed:
     * leagues off, opted out, blocked, the week is already being closed (a request that started before Monday 00:00), or - in a v2
     * week - no category was given.
     *
     *   v1 week: called at the first activity of a local day (DailyStatsService, RunFinisher); every group is `free`; `$category` is
     *            ignored;
     *   v2 week: called at the first credit of a rating day of the week ({@see WordScoreService}) with the category of the learner's
     *            week (`learner_score_periods`, fixed by the first credit): a group of that category and of the learner's tier - the
     *            tier is carried over between the categories (docs/32 §5.1). A call without a category (the v1 hooks of the first
     *            grade / run of a day) returns null at once: a learner with no credit this week is never seated (no empty rows).
     */
    public function place(Learner $learner, CarbonImmutable $now, ?ScoreCategory $category = null): ?LeagueMember
    {
        if (! $this->enabled() || $this->isOptedOut($learner) || $learner->blocked_at !== null) {
            return null;
        }

        $week = $this->currentWeek($now);
        $v2 = $week->rules_version >= 2;
        if ($v2 && $category === null) {
            return null; // a v1 hook in a v2 week: not a statement more (the credit hook seats, with the category)
        }

        $existing = $this->membership($learner, $week);
        if ($existing !== null) {
            return $existing;
        }

        $category = $v2 ? $category : ScoreCategory::Free;

        // The tier is the verdict of the learner's own last group. Closing the week is the scheduler's job and
        // never happens here: if that one group was not finalised yet, it is — alone, in a few milliseconds.
        $tier = $this->tierFor($learner, $week, $now);
        $capacity = max(1, (int) config('leagues.capacity', 30));

        try {
            /** @var LeagueMember $member */
            $member = DB::transaction(function () use ($learner, $week, $tier, $capacity, $now, $category, $v2): LeagueMember {
                // The barrier against the closer: the week row is held FOR SHARE for the rest of this transaction,
                // so `open → closing` (an UPDATE, FOR NO KEY UPDATE) waits for us or happened before — never under us.
                /** @var LeagueWeek|null $open */
                $open = LeagueWeek::query()->whereKey($week->id)->sharedLock()->first();
                if ($open === null || $open->status !== LeagueWeekStatus::Open->value) {
                    throw new LeagueWeekNotOpen;
                }

                $group = $this->groupWithSeat($week, $category, $tier, $capacity);

                try {
                    // Savepoint: the unique violation of a parallel placement must not poison the transaction.
                    /** @var LeagueMember $member */
                    $member = DB::transaction(static fn (): LeagueMember => LeagueMember::query()->create([
                        'league_group_id' => $group->id,
                        'league_week_id' => $week->id,
                        'learner_id' => $learner->id,
                        'tier_before' => $tier,
                        'category' => $v2 ? $category->value : null,
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
            ['week_id' => $week->id, 'group_id' => $member->league_group_id, 'tier' => $tier, ...($v2 ? ['category' => $category->value] : [])],
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

    /**
     * The learner's most recent closed membership (result known), if any — what the overview shows as "last week".
     * "Most recent" is by the week's date, as in {@see tierFor()}, not by row order: a week row can be created after
     * the row of a later week.
     */
    public function lastResult(Learner $learner): ?LeagueMember
    {
        /** @var LeagueMember|null $member */
        $member = LeagueMember::query()
            ->join('league_weeks', 'league_weeks.id', '=', 'league_members.league_week_id')
            ->where('league_members.learner_id', $learner->id)
            ->whereNotNull('league_members.result')
            ->orderByDesc('league_weeks.starts_on')
            ->select('league_members.*')
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

        // The cache holds arrays of scalars, never DTO objects: the store may refuse to unserialize a class.
        /** @var list<LeagueStandingArray> $rows */
        $rows = PureCache::store()->remember(
            self::cacheKey($group->id, $week->rules_version),
            $ttl,
            fn (): array => array_map(
                static fn (LeagueStanding $standing): array => $standing->toArray(),
                $this->computeStandings($group, $week),
            ),
        );

        return collect(array_map(LeagueStanding::fromArray(...), $rows));
    }

    /**
     * The "Today" line: the learner's place this week, or null when not placed (or leagues off). From the cache when the
     * learner is placed (the facts of the placement, {@see MEMBER_PREFIX}, and the standings of the group): no statement;
     * else the week and the membership are read (two statements) and, once placed, the facts are cached for the week.
     */
    public function todayLine(Learner $learner, CarbonImmutable $now): ?TodayLeague
    {
        if (! $this->enabled()) {
            return null;
        }

        [$facts, $group] = $this->memberFacts($learner, $now);
        if ($facts === null) {
            return null;
        }

        $standings = $this->standingsOfGroup($facts['group_id'], $facts['version'], $now, $group);
        $mine = $standings->first(static fn (LeagueStanding $s): bool => $s->memberId === $facts['member_id']);
        if ($mine === null) {
            return null;
        }

        return new TodayLeague($facts['tier'], $this->tierKey($facts['tier']), $mine->rank, $mine->points, $standings->count());
    }

    /** The key of the learner's placement facts for the week that contains `$now` ({@see MEMBER_PREFIX}). */
    public static function memberKey(int $learnerId, CarbonImmutable $now): string
    {
        return self::MEMBER_PREFIX.$learnerId.':'.$now->setTimezone(self::leagueZone())->startOfWeek(CarbonInterface::MONDAY)->toDateString();
    }

    /**
     * The placement facts of the learner in the current week - from the cache, else from `league_weeks` + `league_members`
     * (the week, the membership, its group), cached until the week is over once the learner is placed. Null = not placed
     * (never cached).
     *
     * The facts carry the rules version of the week (which standings key to read); the cached entry of a v1 week has no `version`
     * (its shape of before S8), a v2 week's has it.
     *
     * @return array{0: array{week_id: int, member_id: int, group_id: int, tier: int, version: int}|null, 1: LeagueGroup|null} the facts
     *                                                                                                                         and the group,
     *                                                                                                                         when it was
     *                                                                                                                         read here
     */
    private function memberFacts(Learner $learner, CarbonImmutable $now): array
    {
        $key = self::memberKey((int) $learner->id, $now);

        $cached = PureCache::store()->get($key);
        if (is_array($cached) && isset($cached['week_id'], $cached['member_id'], $cached['group_id'], $cached['tier'])) {
            return [[
                'week_id' => (int) $cached['week_id'],
                'member_id' => (int) $cached['member_id'],
                'group_id' => (int) $cached['group_id'],
                'tier' => (int) $cached['tier'],
                'version' => (int) ($cached['version'] ?? 1),
            ], null];
        }

        $week = $this->findWeek($now);
        $member = $week === null ? null : $this->membership($learner, $week);
        $group = $member?->group;
        if ($member === null || $group === null) {
            return [null, null];
        }
        $group->setRelation('week', $week);

        $facts = [
            'week_id' => (int) $week->id,
            'member_id' => (int) $member->id,
            'group_id' => (int) $group->id,
            'tier' => (int) $group->tier,
            'version' => (int) $week->rules_version,
        ];
        // The cached facts of a v1 week keep their shape (`version` absent = 1); a v2 week adds its version (the key of its standings).
        $cachedFacts = $facts['version'] >= 2 ? $facts : array_diff_key($facts, ['version' => true]);
        // Until an hour past the next Monday 00:00 of the league zone: the facts cannot change before, and the key of the
        // next week is another one.
        $ttl = $this->weekStart($now)->addWeek()->addHour()->getTimestamp() - $now->getTimestamp();
        PureCache::store()->put($key, $cachedFacts, max(60, $ttl));

        return [$facts, $group];
    }

    /**
     * The standings of a group of the CURRENT week by its id: the cached rows when there are any (no statement), else the
     * group is read and {@see standings()} computes and caches them. The week of the facts is the one that contains
     * `$now`, and a group of a week that has not ended is never finalised ({@see closeGroup()} requires {@see hasEnded()}),
     * so the cached rows are the live standings, as {@see standings()} would return them.
     *
     * @return Collection<int, LeagueStanding>
     */
    private function standingsOfGroup(int $groupId, int $version, CarbonImmutable $now, ?LeagueGroup $group): Collection
    {
        $rows = PureCache::store()->get(self::cacheKey($groupId, $version));
        if (is_array($rows)) {
            /** @var list<LeagueStandingArray> $rows */
            return collect(array_map(LeagueStanding::fromArray(...), $rows));
        }

        /** @var LeagueGroup|null $group */
        $group ??= LeagueGroup::query()->find($groupId);

        return $group === null ? new Collection : $this->standings($group, $now);
    }

    /** What `GET /api/twa/league` shows. */
    public function overview(Learner $learner, CarbonImmutable $now): LeagueOverview
    {
        $monday = $this->weekStart($now);
        $enabled = $this->enabled();
        $week = $enabled ? $this->findWeek($now) : null;
        $member = $week === null ? null : $this->membership($learner, $week);
        $group = $member?->group;
        if ($group !== null) {
            $group->setRelation('week', $week); // the week is in hand: standings() would read it again
        }

        $membership = $member === null || $group === null ? null : $this->membershipView($member, $group, $now);
        $emoji = $membership === null ? [] : $this->groupEmoji($group, $membership->standings, $now);

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
            membership: $membership,
            lastResult: $enabled ? $this->lastResult($learner) : null,
            // The week of the rules (docs/32 §6.1): the row's, or - before anybody was placed - what the row will get.
            rulesVersion: $week !== null ? (int) $week->rules_version : $this->scoreRules->versionFor($monday),
            proEmoji: $emoji,
        );
    }

    /**
     * The emoji of Pro of the members of a group (docs/31 §5а): ONE statement ({@see ProEmojiService::visibleForMany()}) on a miss,
     * none while the open group's key is warm ({@see EMOJI_PREFIX}); a finalised group is not cached (its week is over, nobody reads it
     * twice).
     *
     * @param  list<LeagueStanding>  $standings
     * @return array<int, string> learner id => emoji
     */
    public function groupEmoji(LeagueGroup $group, array $standings, CarbonImmutable $now): array
    {
        $ids = array_map(static fn (LeagueStanding $s): int => $s->learnerId, $standings);
        if ($ids === []) {
            return [];
        }
        if ($group->isClosed() || $group->week === null || $group->week->isClosed()) {
            return $this->proEmoji->visibleForMany($ids, $now);
        }

        $ttl = max(1, (int) config('leagues.standings_cache_seconds', 60));
        /** @var array<int|string, string> $emoji */
        $emoji = PureCache::store()->remember(self::EMOJI_PREFIX.$group->id, $ttl, fn (): array => $this->proEmoji->visibleForMany($ids, $now));

        $out = [];
        foreach ($emoji as $id => $value) {
            $out[(int) $id] = (string) $value;
        }

        return $out;
    }

    /**
     * The learner chose another emoji of Pro or took it back (docs/31 §5а): the emoji of their group of the current week is dropped, so
     * the table shows the new one at once. The placement facts come from the cache (or two reads); not placed - nothing to drop.
     */
    public function proEmojiChanged(Learner $learner, CarbonImmutable $now): void
    {
        if (! $this->enabled()) {
            return;
        }

        [$facts] = $this->memberFacts($learner, $now);
        if ($facts !== null) {
            PureCache::forgetMany([self::EMOJI_PREFIX.$facts['group_id']]);
        }
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
            // The cached live standings (at most `standings_cache_seconds` old) give way to the final ones at once.
            DB::afterCommit(static fn () => PureCache::forgetMany([self::cacheKey($locked->id, $week->rules_version)]));

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
            ->where('starts_on', '<', StoredDate::of($this->weekStart($now)->toDateString()))
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
            ->where('league_weeks.starts_on', '<', StoredDate::of($week->starts_on->toDateString()))
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
        if ($week->rules_version >= 2) {
            [$ranked, $unranked] = $this->rankedStandingsV2($group, $week);
            $outcomes = $this->outcomes($group, $ranked);
            // A member out of the ranks (deleted, blocked, opted out) keeps the tier: no rank, no promotion, no demotion.
            foreach ($unranked as $standing) {
                $outcomes[$standing->memberId] = [$standing, LeagueResult::Stayed];
            }
            $this->writeOutcomes($outcomes, $now);
        } else {
            $this->writeOutcomes($this->outcomes($group, $this->computeStandings($group, $week)), $now);
        }
        $group->forceFill(['closed_at' => $now])->save();
    }

    /** The cache key of a group's live standings: the v1 key for a v1 week, `v3` for a v2 week (another meaning of the rows). */
    public static function cacheKey(int $groupId, int $rulesVersion): string
    {
        return ($rulesVersion >= 2 ? self::CACHE_PREFIX_V2 : self::CACHE_PREFIX).$groupId;
    }

    /**
     * Final points / rank / result (and, in a v2 week, the tie-break facts) of a whole group in ONE statement, bindings only.
     * PostgreSQL: `UPDATE … FROM (VALUES …)` with typed parameters; sqlite (tests) has no typed VALUES list and gets the equivalent
     * CASE form. A standing of rank 0 (a v2 member out of the ranks) gets no `final_rank`; a v1 standing writes NULL facts.
     *
     * @param  array<int, array{0: LeagueStanding, 1: LeagueResult}>  $outcomes  keyed by member id
     */
    private function writeOutcomes(array $outcomes, CarbonImmutable $now): void
    {
        if ($outcomes === []) {
            return;
        }

        $stamp = (string) (new LeagueMember)->fromDateTime($now);
        $columns = [
            'final_points' => static fn (LeagueStanding $s, LeagueResult $r): int => $s->points,
            'final_rank' => static fn (LeagueStanding $s, LeagueResult $r): ?int => $s->rank > 0 ? $s->rank : null,
            'result' => static fn (LeagueStanding $s, LeagueResult $r): string => $r->value,
            'final_long_words' => static fn (LeagueStanding $s, LeagueResult $r): ?int => $s->longWords,
            'final_days' => static fn (LeagueStanding $s, LeagueResult $r): ?int => $s->activeDays,
        ];

        if (DB::connection()->getDriverName() === 'pgsql') {
            $rows = [];
            $bindings = [$stamp];
            foreach ($outcomes as $memberId => [$standing, $result]) {
                $rows[] = '(?::bigint, ?::integer, ?::smallint, ?::varchar, ?::smallint, ?::smallint)';
                $bindings[] = $memberId;
                foreach ($columns as $value) {
                    $bindings[] = $value($standing, $result);
                }
            }

            DB::update(
                'update league_members as lm set final_points = v.final_points, final_rank = v.final_rank, '
                .'result = v.final_result, final_long_words = v.final_long_words, final_days = v.final_days, updated_at = ? '
                .'from (values '.implode(', ', $rows).') '
                .'as v (member_id, final_points, final_rank, final_result, final_long_words, final_days) where lm.id = v.member_id',
                $bindings,
            );

            return;
        }

        $branches = str_repeat(' when ? then ?', count($outcomes));
        $bindings = [];
        $sets = [];
        foreach ($columns as $column => $value) {
            $sets[] = "{$column} = case id{$branches} end";
            foreach ($outcomes as $memberId => [$standing, $result]) {
                array_push($bindings, $memberId, $value($standing, $result));
            }
        }
        $bindings[] = $stamp;
        $ids = array_keys($outcomes);
        array_push($bindings, ...$ids);

        DB::update(
            'update league_members set '.implode(', ', $sets).', '
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
     * The open group of the category and tier with a free seat, locked for this transaction, or a new one. A v1 week has only `free`
     * groups.
     *
     * PostgreSQL: `FOR UPDATE SKIP LOCKED` first (parallel placements take different groups, nobody
     * waits); when nothing is free or unlocked, an advisory lock per (week, category, tier) serialises the
     * would-be creators, and under it a plain `FOR UPDATE` waits for the busy group — PostgreSQL
     * re-evaluates `members_count < capacity` on the committed row — so a group is opened only when
     * every existing one is really full. No cycle: a transaction that holds a group row never asks
     * for the advisory lock. Other drivers (sqlite in tests) have no row locks and run serially.
     * The lookup is the index `(league_week_id, category, tier)` (2026_11_26_100001).
     */
    private function groupWithSeat(LeagueWeek $week, ScoreCategory $category, int $tier, int $capacity): LeagueGroup
    {
        $candidates = static fn () => LeagueGroup::query()
            ->where('league_week_id', $week->id)
            ->where('category', $category->value)
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

            // One key per (week, category, tier): 16 tiers at most per category (config has 5).
            DB::statement('select pg_advisory_xact_lock(?::bigint)', [$week->id * 32 + ($category === ScoreCategory::Pro ? 16 : 0) + min(15, $tier)]);
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
            'category' => $category->value,
            'capacity' => $capacity,
            'members_count' => 0,
        ]);

        return $created;
    }

    /**
     * Fresh standings of an open group, best first: the v1 formula for a v1 week, the best days of v2 for a v2 week (only the members
     * in the ranks: {@see rankedStandingsV2()}).
     *
     * @return list<LeagueStanding>
     */
    private function computeStandings(LeagueGroup $group, LeagueWeek $week): array
    {
        if ($week->rules_version >= 2) {
            return $this->rankedStandingsV2($group, $week)[0];
        }

        return $this->standingsV1($group, $week);
    }

    /**
     * v1: one grouped query over the members, their names and the `learner_daily_stats` rows of the week's dates. The points of the
     * game (`game_points`) are added as stored: the daily cap was applied when the run was finished (docs/18 §12.6), the formula knows
     * nothing of runs.
     *
     * @return list<LeagueStanding>
     */
    private function standingsV1(LeagueGroup $group, LeagueWeek $week): array
    {
        $from = StoredDate::of($week->starts_on->toDateString());
        $to = StoredDate::of($week->ends_on->toDateString());
        [$dayPoints, $weights] = LeaguePoints::dayExpression('s');

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
                ."coalesce(sum({$dayPoints}), 0) as points",
                $weights,
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

    /**
     * v2 (docs/32 §3.5, §5.1): ONE statement over the members of the group and their `learner_score_days` of the week - at most
     * `capacity` × 7 rows, read through the unique `(learner_id, rating_day)`:
     *
     *   - the days of a learner are numbered `row_number() over (partition by learner order by week_points desc, week_long desc,
     *     rating_day)` - the deterministic order of docs/32 §3.5 - and the first `best_days` of them count: a sixth or seventh day
     *     better than the weakest of five replaces it (80+60+50+40+30 = 260, a day of 70 → 300);
     *   - `points` = their `week_points`, `long_words` = their `week_long` (one lexeme cannot get two long repetitions inside a
     *     week: the next interval is at least 7 days), `active_days` = those of them with points;
     *   - the order `points desc, long_words desc, active_days desc`, the member id only to keep a shared place stable; the rank is
     *     the COMPETITION rank of the tuple (1, 2, 2, 4), given here;
     *   - a deleted, blocked or opted-out learner is out of the ranks (the neighbours move up, docs/30) - returned apart, rank 0,
     *     for the close: they keep their tier.
     *
     * @return array{0: list<LeagueStanding>, 1: list<LeagueStanding>} the ranked standings best first, the members out of the ranks
     */
    private function rankedStandingsV2(LeagueGroup $group, LeagueWeek $week): array
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $optedOut = $pgsql
            ? "coalesce(l.meta->>'leagues_opt_out', 'false') = 'true'"
            : "coalesce(json_extract(l.meta, '$.leagues_opt_out'), 0) = 1";
        $best = $this->scoreRules->version($week->rules_version)->bestDays ?? 5;

        $rows = DB::select(
            'select lm.id as member_id, lm.learner_id, max(l.first_name) as first_name, '
            ."max(case when l.deleted_at is null and l.blocked_at is null and not ({$optedOut}) then 1 else 0 end) as ranked, "
            .'coalesce(sum(case when d.rn <= ? then d.week_points else 0 end), 0) as points, '
            .'coalesce(sum(case when d.rn <= ? then d.week_long else 0 end), 0) as long_words, '
            .'coalesce(sum(case when d.rn <= ? and d.week_points > 0 then 1 else 0 end), 0) as active_days '
            .'from league_members lm '
            .'join learners l on l.id = lm.learner_id '
            .'left join ('
            .'select s.learner_id, s.week_points, s.week_long, '
            .'row_number() over (partition by s.learner_id order by s.week_points desc, s.week_long desc, s.rating_day) as rn '
            .'from learner_score_days s '
            .'where s.learner_id in (select m.learner_id from league_members m where m.league_group_id = ?) '
            .'and s.rating_day >= ? and s.rating_day <= ?'
            .') d on d.learner_id = lm.learner_id '
            .'where lm.league_group_id = ? '
            .'group by lm.id, lm.learner_id '
            .'order by points desc, long_words desc, active_days desc, lm.id',
            [
                $best, $best, $best,
                $group->id,
                StoredDate::of($week->starts_on->toDateString()),
                StoredDate::of($week->ends_on->toDateString()),
                $group->id,
            ],
        );

        $ranked = [];
        $unranked = [];
        $previous = null;
        foreach ($rows as $row) {
            $standing = new LeagueStanding(
                memberId: (int) $row->member_id,
                learnerId: (int) $row->learner_id,
                name: is_string($row->first_name) && $row->first_name !== '' ? $row->first_name : null,
                points: (int) $row->points,
                rank: 0,
                longWords: (int) $row->long_words,
                activeDays: (int) $row->active_days,
            );

            if ((int) $row->ranked !== 1) {
                $unranked[] = $standing;

                continue;
            }

            $position = count($ranked) + 1;
            $rank = $previous !== null && $previous->tiesWith($standing) ? $previous->rank : $position;
            $standing = new LeagueStanding($standing->memberId, $standing->learnerId, $standing->name, $standing->points, $rank, $standing->longWords, $standing->activeDays);
            $ranked[] = $standing;
            $previous = $standing;
        }

        return [$ranked, $unranked];
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
            ->orderBy('id')
            ->get();

        return $members->toBase()->map(static function (LeagueMember $m): LeagueStanding {
            $name = $m->learner?->first_name;

            return new LeagueStanding(
                memberId: $m->id,
                learnerId: $m->learner_id,
                name: $name === '' ? null : $name,
                points: (int) $m->final_points,
                rank: (int) $m->final_rank,
                longWords: $m->final_long_words,
                activeDays: $m->final_days,
            );
        })->values();
    }

    /**
     * Results of a group at the close: the top `promote` promoted (unless the tier is the top one), the bottom `demote` demoted
     * (unless the tier is 0), the rest stayed. Promotion wins when a small group overlaps both zones.
     *
     * By RANK, not by position: in a v1 week every rank is distinct, so nothing changes there; in a v2 week (competition ranks,
     * docs/32 §5.1, owner's decision docs/31 §5) everybody who shares the last promoted place is promoted, and nobody who shares a
     * place with the last safe member is demoted (rank > count − demote means "worse than every safe position").
     *
     * @param  list<LeagueStanding>  $standings  the ranked members, best first
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
            category: $group->week?->rules_version >= 2 ? $group->category : null,
            rulesVersion: (int) ($group->week->rules_version ?? 1),
            nextGap: $this->nextGap($list, $mine),
        );
    }

    /**
     * The points that overtake the nearest member with a strictly better place for sure: points are the first key, so one point more
     * than theirs always wins (docs/32 §5.3); null for a leader.
     *
     * @param  list<LeagueStanding>  $standings  best first
     */
    private function nextGap(array $standings, LeagueStanding $mine): ?int
    {
        $better = null;
        foreach ($standings as $standing) {
            if ($standing->rank >= $mine->rank) {
                break;
            }
            $better = $standing;
        }

        return $better === null ? null : $better->points - $mine->points + 1;
    }
}
