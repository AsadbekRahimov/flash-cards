<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Administration\Services\PendingLeagueMonthsBadge;
use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Engagement\DTO\BoardCursor;
use App\Domain\Engagement\DTO\BoardKeys;
use App\Domain\Engagement\DTO\BoardPageCursor;
use App\Domain\Engagement\DTO\BoardRow;
use App\Domain\Engagement\DTO\BoardScope;
use App\Domain\Engagement\DTO\LastMonthView;
use App\Domain\Engagement\DTO\MonthlyBoardPage;
use App\Domain\Engagement\DTO\MonthlyMeView;
use App\Domain\Engagement\DTO\MonthlyMyLine;
use App\Domain\Engagement\DTO\MonthlyPrize;
use App\Domain\Engagement\DTO\MonthlyRatingOverview;
use App\Domain\Engagement\DTO\MonthlyStanding;
use App\Domain\Engagement\DTO\RegionChoice;
use App\Domain\Engagement\DTO\RegionStanding;
use App\Domain\Engagement\Enums\LeagueMonthCandidateStatus;
use App\Domain\Engagement\Enums\LeagueMonthStatus;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Exceptions\InvalidBoardCursor;
use App\Domain\Engagement\Exceptions\RegionNotSet;
use App\Domain\Engagement\Exceptions\RegionScopeUnavailable;
use App\Domain\Engagement\Services\Scoring\AllTimeRatingService;
use App\Domain\Engagement\Services\Scoring\BoardReader;
use App\Domain\Engagement\Services\Scoring\ScoreBoard;
use App\Domain\Engagement\Services\Scoring\ScoreRules;
use App\Domain\Engagement\Services\Scoring\ScoreTotalsService;
use App\Domain\Engagement\Support\LeaguePoints;
use App\Domain\Learners\Services\ProEmojiService;
use App\Domain\Learners\Support\Regions;
use App\Domain\Learners\Support\StoredDate;
use App\Domain\Operations\Support\PureCache;
use App\Models\LeagueMonth;
use App\Models\LeagueMonthCandidate;
use App\Models\LeagueMonthScore;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The monthly rating with prizes (docs/13 §7.6). One rating of everybody per calendar month of the league time zone, on
 * the points of the weekly leagues ({@see LeaguePoints}) summed over the `learner_daily_stats` rows dated inside the
 * month — a row belongs to the month by its local date, exactly as a row belongs to a league week.
 *
 *   scores   — `league_month_scores` holds the sum per learner, the day points capped by `leagues.monthly.daily_cap`
 *              (the weekly formula stays uncapped), plus the counters the review shows. It is written by ONE
 *              `INSERT … SELECT … ON CONFLICT DO UPDATE` ({@see upsertScores}) that recomputes a set of learners from
 *              their day rows — all of them (`full`), those with a row dated in the last `refresh_recent_days` (the
 *              scheduler every 10 minutes: a day row older than that never changes), or one learner (the Mini App
 *              refreshes the viewer's own row when it shows the rating). Nothing of this runs inside the review
 *              transaction; `learners` is never locked (docs/13 §3 rule 5);
 *   reading  — the top list is one indexed read, cached `top_cache_seconds` as arrays (`league:month:top:v1:{month}`);
 *              a learner's place is "participants ahead of me" + 1, one indexed count; eligibility for a prize
 *              (`min_active_days`, `min_account_days`) is computed from the row and the learner's `created_at`;
 *   closing  — {@see closeMonth}: `open → closing` under the month row lock (the barrier: a refresh writes only to an
 *              `open` month, under `FOR SHARE` of that row, so once the barrier commits nobody writes), then ONE
 *              transaction under `FOR UPDATE`: the authoritative full recompute, the final ranks (a window function),
 *              the candidates (the best `candidates` eligible participants with their signals) and
 *              `closing → pending_review`. Idempotent: a closed month is left alone; a crash leaves `closing` and the
 *              next run redoes the one transaction. The administrator's part ({@see MonthlyRatingReviewService}) happens
 *              under the same row lock.
 *
 * Order of locks: `league_months` → `league_month_scores` → `league_month_candidates` → `audit_logs` / `product_events`;
 * neither `learners` nor anything of the weekly leagues is ever locked here.
 *
 * @phpstan-import-type MonthlyStandingArray from MonthlyStanding
 * @phpstan-import-type BoardRowArray from BoardRow
 */
final class MonthlyRatingService
{
    /** `v1` = arrays of {@see MonthlyStanding::toArray()} of a v1 month (one common table). */
    public const TOP_PREFIX = 'league:month:top:v1:';

    /**
     * `league:month:top:v2:{month}:{category}` - the top of one category of a v2 month (docs/32 §5.5, docs/19 §3): competition ranks,
     * the tie-break facts. Another table and another meaning, so another key; the category is in it.
     */
    public const TOP_PREFIX_V2 = 'league:month:top:v2:';

    /**
     * `league:month:rank:v1:{month}:{category}:{points}:{long_words}:{active_days}` - the competition place of a tuple in a table of an
     * open v2 month (an int; docs/32 §5.3 "кэш своего места", docs/19 §3). The place depends only on the tuple and on the others, so the
     * key holds the tuple: the viewer's own credit (a new tuple after the refresh of their row) reads a new key at once - no
     * invalidation needed - and only the moves of the others lag behind by up to `monthly.rank_cache_seconds` (60 s). Learners with the
     * same tuple share the entry. The worst case without it is one count of ~10 ms (docs/32 §12.6).
     */
    public const RANK_PREFIX = 'league:month:rank:v1:';

    /**
     * `league:month:near:v1:{month}:{category}:{learner}:{points}:{long_words}:{active_days}` - the viewer's neighbours in an open v2
     * month (`{above, below, rank, next_gap}`, the rows as {@see BoardRow::toArray()} with the emoji of Pro): six statements and the emoji
     * once a minute at most; the same rule of the key as {@see RANK_PREFIX}. An emoji of a neighbour whose Pro has run out shows at most
     * the TTL.
     */
    public const NEAR_PREFIX = 'league:month:near:v1:';

    /**
     * `league:month:count:v1:{month}:{category|all}` - the number of participants of a table of the month (an int; `all` = the one table
     * of a v1 month), shown next to the top and deciding whether the list goes on after it. Lives and dies with the top of the same
     * table: the same TTL (`monthly.top_cache_seconds`) and the same drops ({@see topKeys()}), so the two never disagree for longer
     * than they do today. Without it every `GET /league/month` counted the whole table (docs/38 §3.2).
     */
    public const COUNT_PREFIX = 'league:month:count:v1:';

    /** What a rating day without `updated_at` reads as in {@see freshScore()}: later than any write, so the row is recomputed. */
    private const NEVER_STAMPED = '9999-12-31 00:00:00';

    public function __construct(
        private readonly ProductEventRecorder $events,
        private readonly LeagueService $leagues,
        private readonly ScoreRules $scoreRules,
        private readonly ScoreBoard $board,
        private readonly ProEmojiService $proEmoji,
        private readonly EntitlementResolver $entitlements,
        private readonly ScoreTotalsService $totals,
        private readonly BoardReader $reader,
    ) {}

    /** Whether the month counts by the word points v2 (`league_months.rules_version`, written at creation, never changed). */
    public static function isV2(LeagueMonth $month): bool
    {
        return $month->rules_version >= 2;
    }

    public function enabled(): bool
    {
        return (bool) config('leagues.enabled', true) && (bool) config('leagues.monthly.enabled', true);
    }

    public function timezone(): DateTimeZone
    {
        return LeagueService::leagueZone();
    }

    // --- calendar ----------------------------------------------------------------------------

    /** The 1st 00:00 (league zone) of the month that contains `$now`. */
    public function monthStart(CarbonImmutable $now): CarbonImmutable
    {
        return $now->setTimezone($this->timezone())->startOfMonth();
    }

    /** The instant the month ends: the 1st of the next month 00:00 in the league zone (as a UTC instant). */
    public function endsAt(LeagueMonth $month): CarbonImmutable
    {
        return $this->firstDayAt($month)->addMonth()->utc();
    }

    /** When the month is closed: {@see endsAt()} plus `close_delay_minutes` (the lagging local evenings west of the zone). */
    public function closeDueAt(LeagueMonth $month): CarbonImmutable
    {
        return $this->endsAt($month)->addMinutes(max(0, (int) config('leagues.monthly.close_delay_minutes', 180)));
    }

    public function hasEnded(LeagueMonth $month, CarbonImmutable $now): bool
    {
        return ! $now->isBefore($this->endsAt($month));
    }

    public function isDue(LeagueMonth $month, CarbonImmutable $now): bool
    {
        return ! $now->isBefore($this->closeDueAt($month));
    }

    /** Calendar days left in the month including today (league zone): 1 on the last day, 0 once it is over. */
    public function daysLeft(LeagueMonth $month, CarbonImmutable $now): int
    {
        $today = $now->setTimezone($this->timezone())->startOfDay();
        $end = $this->firstDayAt($month)->addMonth();

        return max(0, (int) $today->diffInDays($end, false));
    }

    /** The row of the month that contains `$now`, created when missing (a parallel creator's row is reused). */
    public function currentMonth(CarbonImmutable $now): LeagueMonth
    {
        $first = $this->monthStart($now);
        $existing = $this->findMonthStartingOn($first->toDateString());
        if ($existing !== null) {
            return $existing;
        }

        try {
            // Savepoint: a lost creation race must not poison an outer (PostgreSQL) transaction.
            // The rules version is written once, here (docs/32 §6.1): the month is counted and closed by it, and the learners' months
            // fixed by their first credit take it from this row (ScorePeriodService::insert).
            $rulesVersion = $this->scoreRules->versionFor($first->toDateString());
            /** @var LeagueMonth $month */
            $month = DB::transaction(static fn (): LeagueMonth => LeagueMonth::query()->create([
                'starts_on' => $first->toDateString(),
                'ends_on' => $first->endOfMonth()->toDateString(),
                'status' => LeagueMonthStatus::Open->value,
                'rules_version' => $rulesVersion,
            ]));

            return $month;
        } catch (UniqueConstraintViolationException) {
            $month = $this->findMonthStartingOn($first->toDateString());
            if ($month === null) {
                throw new RuntimeException('League month vanished after a unique violation.');
            }

            return $month;
        }
    }

    public function findMonth(CarbonImmutable $now): ?LeagueMonth
    {
        return $this->findMonthStartingOn($this->monthStart($now)->toDateString());
    }

    /** The row of the month before the one that contains `$now`, or null when it never existed. */
    public function previousMonth(CarbonImmutable $now): ?LeagueMonth
    {
        return $this->findMonthStartingOn($this->monthStart($now)->subMonth()->toDateString());
    }

    /** @param  string  $firstDay  `Y-m-01` */
    public function findMonthStartingOn(string $firstDay): ?LeagueMonth
    {
        /** @var LeagueMonth|null $month */
        $month = LeagueMonth::query()->where('starts_on', StoredDate::of($firstDay))->first();

        return $month;
    }

    // --- scores ------------------------------------------------------------------------------

    /**
     * Recomputes the scores of an `open` month from the day rows — every learner with a row in the month (`$full`), or
     * only those with a row dated in the last `refresh_recent_days` (the scheduler's run). Under `FOR SHARE` of the month
     * row, so the close's barrier waits for a run in flight and no run starts after it. Returns false when the month is
     * not open (or the rating is off): nothing was written.
     */
    public function refresh(LeagueMonth $month, CarbonImmutable $now, bool $full = false): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $written = DB::transaction(function () use ($month, $now, $full): bool {
            $locked = $this->openForShare($month);
            if ($locked === null) {
                return false;
            }

            $recentFrom = $full ? null : $this->recentFrom($locked, $now);
            $this->fillMissingRegions($locked, $recentFrom);
            $this->upsertScores($locked, $now, null, $recentFrom);

            return true;
        });

        if ($written) {
            $this->stampRefresh($month, $now);
            PureCache::forgetMany(self::topKeys($month->id));
        }

        return $written;
    }

    /**
     * Recomputes ONE learner's row of an `open` month (the Mini App shows the viewer a fresh line; two short statements).
     * Returns false when the month is not open: nothing was written.
     */
    public function refreshLearner(LeagueMonth $month, Learner $learner, CarbonImmutable $now): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return DB::transaction(function () use ($month, $learner, $now): bool {
            $locked = $this->openForShare($month);
            if ($locked === null) {
                return false;
            }

            $this->upsertScores($locked, $now, $learner->id, null);

            return true;
        });
    }

    /**
     * The viewer's row of the month for the Mini App, fresh: what {@see refreshLearner()} followed by {@see scoreOf()} gives, without
     * the recompute when it cannot change anything (docs/38 §3.2).
     *
     * A v1 month and a month that is not open: as before - the recompute (it does nothing to a closed month), then the read.
     *
     * An open v2 month: ONE statement reads the row together with the number of the learner's rating days in the month and the moment
     * the last of them changed (`learner_score_days.updated_at`: every credit and every correction stamps it). The recompute - the
     * `FOR SHARE` of the month, the upsert from three sources and its transaction - runs only when it has something to write:
     *
     *   no rating day in the month            nothing to compute (a v2 row exists only from rating days): the row as it is, usually none;
     *   the row is newer than every day       it already holds them: the row as it is;
     *   otherwise (no row yet, or a day has changed in the same second as the row or later)   recompute, read again.
     *
     * Strictly newer: a credit stamped in the same second as the row's last write may not be in it. What this check cannot see - a
     * credit whose transaction took its timestamp before a recompute that changed the row and committed after it, or the clocks of
     * two application servers apart - leaves the viewer's own line behind until their next credit or the scheduled refresh of the
     * recently active (`leagues:refresh-month`, every 10 minutes); the top and the places of the others lag by their caches anyway.
     * The admin's signals of the row (reviews, time: `learner_daily_stats`) are not shown in the Mini App and follow the same refresh.
     * A change of the right to the rating recomputes the row itself ({@see participationChanged()}).
     */
    public function freshScore(LeagueMonth $month, Learner $learner, CarbonImmutable $now): ?LeagueMonthScore
    {
        if (! $this->enabled()) {
            return $this->scoreOf($month, $learner->id);
        }

        if (! self::isV2($month) || ! $month->isOpen()) {
            $this->refreshLearner($month, $learner, $now);

            return $this->scoreOf($month, $learner->id);
        }

        // The last change of the learner's rating days of the month; a day without a stamp (never written by the application) reads as
        // "changed after everything", so it always recomputes. NULL = no rating day in the month.
        $changed = DB::table('learner_score_days as d')
            ->where('d.learner_id', $learner->id)
            ->where('d.rating_day', '>=', StoredDate::of($month->starts_on->toDateString()))
            ->where('d.rating_day', '<=', StoredDate::of($month->ends_on->toDateString()))
            ->selectRaw('max(coalesce(d.updated_at, ?))', [self::NEVER_STAMPED]);

        // From the learner's own row (it exists: the request is theirs), so there is a result with or without a row of the month.
        $row = DB::table('learners as l')
            ->leftJoin('league_month_scores as s', static function (JoinClause $join) use ($month): void {
                $join->on('s.learner_id', '=', 'l.id')->where('s.league_month_id', '=', $month->id);
            })
            ->where('l.id', $learner->id)
            ->select('s.*')
            ->selectSub($changed, 'rating_days_changed_at')
            ->first();

        if ($row === null) {
            return null;
        }

        $attributes = get_object_vars($row);
        $changedAt = $attributes['rating_days_changed_at'] ?? null;
        unset($attributes['rating_days_changed_at']);

        $score = ($attributes['id'] ?? null) === null ? null : (new LeagueMonthScore)->newFromBuilder($attributes);

        if ($changedAt === null) {
            return $score;
        }

        // Both stamps are stored the same way (the application's `now`), so they are compared as stored.
        $writtenAt = $attributes['updated_at'] ?? null;
        $fresh = $score !== null && is_string($writtenAt) && is_string($changedAt)
            && CarbonImmutable::parse($writtenAt, 'UTC')->greaterThan(CarbonImmutable::parse($changedAt, 'UTC'));
        if ($fresh) {
            return $score;
        }

        $this->refreshLearner($month, $learner, $now);

        return $this->scoreOf($month, $learner->id);
    }

    /**
     * The learner's right to the rating changed (blocked / unblocked, deleted / restored, opted out of / back into the
     * leagues): recomputes their row of the CURRENT open month at once and drops the cached top, so the list does not keep
     * a blocked leader until the month's close; their "all time" rows too (ScoreTotalsService::learnerChanged). After the commit of the caller's transaction (at once outside one); a failure
     * is reported and logged, never thrown — the action itself has succeeded. A learner with no row in the month: nothing to do.
     */
    public function participationChanged(Learner $learner, ?CarbonImmutable $now = null): void
    {
        // "All time" follows the right to the rating too (docs/32 §6.4): the learner's totals, whatever the month.
        $this->totals->learnerChanged($learner->id, $now);

        DB::afterCommit(function () use ($learner, $now): void {
            try {
                if (! $this->enabled()) {
                    return;
                }

                $now ??= CarbonImmutable::now();
                $month = $this->findMonth($now);
                if ($month === null || ! $month->isOpen() || $this->scoreOf($month, $learner->id) === null) {
                    return;
                }

                if ($this->refreshLearner($month, $learner, $now)) {
                    PureCache::forgetMany(self::topKeys($month->id));
                }
            } catch (Throwable $e) {
                report($e);
                Log::warning('league.month_participation_refresh_failed', ['learner_id' => $learner->id, 'exception' => $e::class, 'error' => $e->getMessage()]);
            }
        });
    }

    /**
     * The scheduler's run: the current month (created when missing) and any earlier month still `open` (the one that
     * ended minutes ago and waits for its close). Returns the number of months refreshed.
     */
    public function refreshDue(CarbonImmutable $now, bool $full = false): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $this->currentMonth($now);

        /** @var Collection<int, LeagueMonth> $months */
        $months = LeagueMonth::query()
            ->where('status', LeagueMonthStatus::Open->value)
            ->where('starts_on', '<=', StoredDate::of($this->monthStart($now)->toDateString()))
            ->orderBy('starts_on')
            ->get();

        $count = 0;
        foreach ($months as $month) {
            if ($this->refresh($month, $now, $full)) {
                $count++;
            }
        }

        return $count;
    }

    // --- reading -----------------------------------------------------------------------------

    /**
     * What `GET /api/twa/league/month` shows. A v2 month has two tables (docs/32 §8.3): the viewer's own - the category of their row of
     * the month, else of their plan (a learner without a credit yet) - and the other one, which `$category` may open read-only: its top,
     * prizes and participants, while `me` stays the viewer's own line and the neighbours are only shown in their own table.
     *
     * A table of a region (docs/35 §2.4, R7; `$choice`): the same, filtered by the region the learners' months are fixed in - any
     * region, read-only unless it is the viewer's (owner's decision docs/31 §5г); no prizes; below `leagues.regions.min_participants`
     * it has no rows. The viewer's place in the region of their month (`me.region`) comes with every answer of a v2 month.
     *
     * @throws RegionScopeUnavailable a table of a region of a month that has none (v1, the rating off)
     * @throws RegionNotSet `scope=region` without a code from a learner who has no region
     */
    public function overview(Learner $learner, CarbonImmutable $now, ?ScoreCategory $requested = null, ?RegionChoice $choice = null): MonthlyRatingOverview
    {
        $choice ??= RegionChoice::all();
        $first = $this->monthStart($now);
        $enabled = $this->enabled();
        $month = $enabled ? $this->currentMonth($now) : null;
        if ($choice->region && ($month === null || ! self::isV2($month))) {
            throw new RegionScopeUnavailable('This month has no tables of a region.');
        }

        $score = $month === null ? null : $this->freshScore($month, $learner, $now);
        $v2 = $month !== null && self::isV2($month);
        $myCategory = $v2 ? $this->categoryOf($learner, $score, $now) : null;
        $category = $v2 ? ($requested ?? $myCategory) : null;
        $region = $v2 ? $this->regionOf($learner, $score, $choice) : null;

        $regionView = null;
        if ($month !== null && $category !== null && $region !== null) {
            $participants = $this->reader->count(BoardScope::month($month->id, $category, $region), BoardKeys::monthRegion($month->id, $category, $region), $this->topTtl());
            $regionView = $this->regionStanding($region, $participants);
            $top = $regionView->shown ? $this->regionTop($month, $category, $region) : [];
        } else {
            $participants = $month === null ? 0 : $this->cachedParticipantsCount($month, $category);
            $top = $month === null ? [] : $this->top($month, $category);
        }
        $last = $top === [] ? null : $top[count($top) - 1];

        // The viewer's own table: their category, and - for a table of a region - the region their month is in.
        $ownTable = $v2 && $score !== null && $category === $myCategory && ($region === null || $region === $score->region_code);

        return new MonthlyRatingOverview(
            enabled: $enabled,
            month: $month,
            startsOn: $first->toDateString(),
            endsOn: $first->endOfMonth()->toDateString(),
            closesAt: $first->addMonth()->utc()->toIso8601String(),
            daysLeft: $month === null ? 0 : $this->daysLeft($month, $now),
            participantsCount: $participants,
            rules: $this->rules(),
            // A table of a region has no prizes (docs/35 §2.4, Р2).
            prizes: $month === null || $region !== null ? [] : $this->prizes($month, $category),
            top: $top,
            me: $month === null ? null : $this->lineOf($month, $learner, $score),
            optedOut: $this->leagues->isOptedOut($learner),
            lastMonth: $enabled ? $this->lastMonth($learner, $now) : null,
            rulesVersion: $month === null ? $this->scoreRules->versionFor($first) : (int) $month->rules_version,
            category: $category,
            myCategory: $myCategory,
            neighbours: $ownTable && ($regionView === null || $regionView->shown) ? $this->cachedNeighbours($month, $score, $region) : null,
            // The top is the first page; more participants than it shows - the list goes on after its last row (no statement: the count is above).
            nextCursor: $v2 && $last !== null && $participants > count($top)
                ? new BoardCursor($last->points, $last->longWords, $last->activeDays, $last->learnerId)
                : null,
            choice: $choice,
            region: $regionView,
            myRegion: $v2 && $score !== null ? $this->myRegion($month, $score) : null,
            readOnly: $v2 && ! ($category === $myCategory && ($region === null || $region === ($score->region_code ?? $learner->region_code))),
        );
    }

    /**
     * A page of a table of the current v2 month after `?after=` (`GET /league/month?category=&after=`, docs/32 §5.3): the read of the
     * board (one statement, three after a cursor) and the emoji of the page (one). The table is `$requested`, else the viewer's own; a
     * table of a region with `$choice` (the cursor must be one of that region; a hidden region has no rows).
     *
     * @throws InvalidBoardCursor the cursor is not one of this table (broken, another month, category or region), or the month is not v2
     * @throws RegionNotSet `scope=region` without a code from a learner who has no region
     */
    public function boardPage(Learner $learner, CarbonImmutable $now, ?ScoreCategory $requested, string $after, int $limit = 50, ?RegionChoice $choice = null): MonthlyBoardPage
    {
        $choice ??= RegionChoice::all();
        $month = $this->enabled() ? $this->findMonth($now) : null;
        if ($month === null || ! self::isV2($month)) {
            throw new InvalidBoardCursor('This month has no pages.');
        }

        $score = $this->scoreOf($month, $learner->id);
        $myCategory = $this->categoryOf($learner, $score, $now);
        $category = $requested ?? $myCategory;
        $region = $this->regionOf($learner, $score, $choice);
        $cursor = BoardPageCursor::decode($after, $month->id, $category, $region);
        if ($cursor === null) {
            throw new InvalidBoardCursor('The cursor is not one of this table.');
        }

        if ($region === null) {
            $page = $this->board($month, $category, $cursor, $limit);

            return new MonthlyBoardPage($month->id, $category, $category !== $myCategory, $this->withEmoji($page['rows']), $page['next']);
        }

        $scope = BoardScope::month($month->id, $category, $region);
        $regionView = $this->regionStanding($region, $this->reader->count($scope, BoardKeys::monthRegion($month->id, $category, $region), $this->topTtl()));
        $page = $regionView->shown ? $this->reader->page($scope, $cursor, $limit) : ['rows' => [], 'next' => null];
        $readOnly = $category !== $myCategory || $region !== ($score->region_code ?? $learner->region_code);

        return new MonthlyBoardPage($month->id, $category, $readOnly, $page['rows'], $page['next'], $regionView);
    }

    /**
     * The region a table of the month is asked for: none (`all`), the code asked for, or the viewer's own - the region their month is
     * fixed in, else (no row of the month, or a month fixed without a region) their region now, which their first credit fixes.
     *
     * @throws RegionNotSet the viewer's own was asked for and they have none
     */
    private function regionOf(Learner $learner, ?LeagueMonthScore $score, RegionChoice $choice): ?string
    {
        if (! $choice->region) {
            return null;
        }

        $region = $choice->code ?? $score->region_code ?? $learner->region_code;
        if ($region === null) {
            throw new RegionNotSet('Choose your region first.');
        }

        return $region;
    }

    /** The viewer's place in the region of their month (`me.region`): null without a region; the place only when shown and a participant. */
    private function myRegion(LeagueMonth $month, LeagueMonthScore $score): ?RegionStanding
    {
        if ($score->region_code === null || $score->category === null) {
            return null;
        }

        $category = ScoreCategory::from($score->category);
        $scope = BoardScope::month($month->id, $category, $score->region_code);
        $keys = BoardKeys::monthRegion($month->id, $category, $score->region_code);
        $standing = $this->regionStanding($score->region_code, $this->reader->count($scope, $keys, $this->topTtl()));
        if (! $standing->shown || ! $score->participant) {
            return $standing;
        }

        $rank = $month->isClosed()
            ? $this->board->rank($scope, $score->points, $score->long_words, $score->active_days)
            : $this->reader->rank($scope, $month->isOpen() ? $keys : null, $this->rankTtl(), $score->points, $score->long_words, $score->active_days);

        return new RegionStanding($standing->code, $standing->participants, true, $standing->minParticipants, $rank);
    }

    /**
     * The top of a table of a region of a v2 month, cached ({@see BoardKeys::monthRegion()}) and dropped with the tops of the month.
     *
     * @return list<MonthlyStanding>
     */
    private function regionTop(LeagueMonth $month, ScoreCategory $category, string $region): array
    {
        return array_map(
            static fn (BoardRow $row): MonthlyStanding => new MonthlyStanding($row->learnerId, $row->name, $row->points, $row->activeDays, $row->rank, $row->longWords, $row->proEmoji),
            $this->reader->top(BoardScope::month($month->id, $category, $region), BoardKeys::monthRegion($month->id, $category, $region), $this->topSize(), $this->topTtl()),
        );
    }

    private function regionStanding(string $region, int $participants): RegionStanding
    {
        $min = AllTimeRatingService::minParticipants();

        return new RegionStanding($region, $participants, $participants >= $min, $min);
    }

    private function topTtl(): int
    {
        return max(1, (int) config('leagues.monthly.top_cache_seconds', 120));
    }

    /** What `GET /api/twa/league/month/me` shows (the finish screen): the viewer's fresh line, nothing of the others. */
    public function meView(Learner $learner, CarbonImmutable $now): MonthlyMeView
    {
        $first = $this->monthStart($now);
        $enabled = $this->enabled();
        $month = $enabled ? $this->currentMonth($now) : null;

        return new MonthlyMeView(
            enabled: $enabled,
            startsOn: $first->toDateString(),
            endsOn: $first->endOfMonth()->toDateString(),
            daysLeft: $month === null ? 0 : $this->daysLeft($month, $now),
            me: $month === null ? null : $this->lineOf($month, $learner, $this->freshScore($month, $learner, $now)),
        );
    }

    /**
     * The viewer's table of a v2 month (docs/32 §12.7): the category of their row, else of their plan now - the table their first credit
     * of the month will fix (a trial Pro and a seat of a center are Pro, В4). The plan is resolved only without a row (the resolver's cache).
     */
    public function categoryOf(Learner $learner, ?LeagueMonthScore $score, CarbonImmutable $now): ScoreCategory
    {
        if ($score?->category !== null) {
            return ScoreCategory::from($score->category);
        }

        return ScoreCategory::fromPlan($this->entitlements->for($learner, $now)->plan);
    }

    /**
     * {@see neighbours()} of the viewer's row with the emoji of the rows, cached {@see NEAR_PREFIX} while the month is open; with `$region`
     * - in the table of that region ({@see BoardKeys::monthRegion()}), only when it is the region of the viewer's month.
     *
     * @return array{above: list<BoardRow>, below: list<BoardRow>, rank: int, next_gap: int|null}|null
     */
    public function cachedNeighbours(LeagueMonth $month, LeagueMonthScore $score, ?string $region = null): ?array
    {
        if (! self::isV2($month) || ! $score->participant || $score->category === null) {
            return null;
        }

        if ($region !== null) {
            // The viewer's neighbours in the table of the region of their month (docs/35 §2.4): the same reads with the region.
            if ($score->region_code !== $region) {
                return null;
            }
            $category = ScoreCategory::from($score->category);
            $scope = BoardScope::month($month->id, $category, $region);
            $keys = $month->isOpen() ? BoardKeys::monthRegion($month->id, $category, $region) : null;
            $rank = $this->reader->rank($scope, $keys, $this->rankTtl(), $score->points, $score->long_words, $score->active_days);

            return $this->reader->neighbours($scope, $keys, $this->rankTtl(), new BoardCursor($score->points, $score->long_words, $score->active_days, $score->learner_id), $rank);
        }

        $compute = function () use ($month, $score): ?array {
            // The place the line of the viewer shows (its own cache of the tuple, {@see rankOf()}): one count for both.
            $around = $this->neighbours($month, $score, 2, $this->rankOf($month, $score));
            if ($around === null) {
                return null;
            }

            // One statement of the emoji for both lists.
            $rows = array_map(static fn (BoardRow $row): array => $row->toArray(), $this->withEmoji([...$around['above'], ...$around['below']]));

            return [
                'above' => array_slice($rows, 0, count($around['above'])),
                'below' => array_slice($rows, count($around['above'])),
                'rank' => $around['rank'],
                'next_gap' => $around['next_gap'],
            ];
        };

        /** @var array{above: list<BoardRowArray>, below: list<BoardRowArray>, rank: int, next_gap: int|null}|null $data */
        $data = $month->isOpen()
            ? PureCache::store()->remember(
                self::NEAR_PREFIX.implode(':', [$month->id, $score->category, $score->learner_id, $score->points, $score->long_words, $score->active_days]),
                $this->rankTtl(),
                $compute,
            )
            : $compute();

        if ($data === null) {
            return null;
        }

        return [
            'above' => array_map(BoardRow::fromArray(...), $data['above']),
            'below' => array_map(BoardRow::fromArray(...), $data['below']),
            'rank' => $data['rank'],
            'next_gap' => $data['next_gap'],
        ];
    }

    /**
     * The rows with the emoji of Pro of their learners: ONE statement for the whole list ({@see ProEmojiService::visibleForMany()}), none
     * for an empty one.
     *
     * @param  list<BoardRow>  $rows
     * @return list<BoardRow>
     */
    private function withEmoji(array $rows): array
    {
        $emoji = $this->proEmoji->visibleForMany(array_map(static fn (BoardRow $row): int => $row->learnerId, $rows));

        return array_map(static fn (BoardRow $row): BoardRow => $row->withEmoji($emoji[$row->learnerId] ?? null), $rows);
    }

    /**
     * The learner chose another emoji of Pro or took it back (docs/31 §5а): the cached tops of the current month (both tables) are dropped,
     * so the new emoji shows at once there; the neighbours of other viewers catch up within {@see rankTtl()}.
     */
    public function proEmojiChanged(CarbonImmutable $now): void
    {
        if (! $this->enabled()) {
            return;
        }

        $month = $this->findMonth($now);
        if ($month !== null) {
            PureCache::forgetMany(self::topKeys($month->id));
        }
    }

    private function rankTtl(): int
    {
        return max(1, (int) config('leagues.monthly.rank_cache_seconds', 60));
    }

    /**
     * The best `leagues.monthly.top` participants of the month, in the order of the rating, with first names. Cached as arrays of
     * scalars; the refresh and the close drop the keys.
     *
     *   v1 month: one table; points, active days, the older account (learner id); every rank distinct;
     *   v2 month: the table of `$category` (Free when null); points, long words, active days - competition ranks (1, 2, 2, 4), the
     *             learner id only keeps a shared place in a stable order.
     *
     * @return list<MonthlyStanding>
     */
    public function top(LeagueMonth $month, ?ScoreCategory $category = null): array
    {
        $ttl = max(1, (int) config('leagues.monthly.top_cache_seconds', 120));
        $category = self::isV2($month) ? ($category ?? ScoreCategory::Free) : null;

        // The emoji of Pro (docs/31 §5а) ride in the cached rows: one statement on a miss, none from the cache. An emoji of a Pro that
        // has run out shows at most the TTL; a change of the choice drops the keys ({@see proEmojiChanged()}).
        /** @var list<MonthlyStandingArray> $rows */
        $rows = PureCache::store()->remember(
            self::topKey($month->id, $category),
            $ttl,
            function () use ($month, $category): array {
                $top = $category === null ? $this->computeTop($month) : array_map(
                    static fn (BoardRow $row): MonthlyStanding => new MonthlyStanding($row->learnerId, $row->name, $row->points, $row->activeDays, $row->rank, $row->longWords),
                    $this->board->page(BoardScope::month($month->id, $category), null, $this->topSize())['rows'],
                );
                $emoji = $this->proEmoji->visibleForMany(array_map(static fn (MonthlyStanding $row): int => $row->learnerId, $top));

                return array_map(
                    static fn (MonthlyStanding $row): array => [...$row->toArray(), 'pro_emoji' => $emoji[$row->learnerId] ?? null],
                    $top,
                );
            },
        );

        return array_map(MonthlyStanding::fromArray(...), $rows);
    }

    /** The learner's own line of the month, or null when they have no row (no activity in the month). */
    public function myLine(LeagueMonth $month, Learner $learner): ?MonthlyMyLine
    {
        return $this->lineOf($month, $learner, $this->scoreOf($month, $learner->id));
    }

    /** {@see myLine()} of a row already read. */
    private function lineOf(LeagueMonth $month, Learner $learner, ?LeagueMonthScore $score): ?MonthlyMyLine
    {
        if ($score === null) {
            return null;
        }

        [$eligible, $missingDays, $missingAccount] = $this->eligibility($month, $score, $learner);

        return new MonthlyMyLine(
            rank: $score->participant ? $this->rankOf($month, $score) : null,
            points: $score->points,
            activeDays: $score->active_days,
            participant: $score->participant,
            eligible: $eligible,
            missingActiveDays: $missingDays,
            missingAccountDays: $missingAccount,
            notCountedReason: $score->participant ? null : $this->notCountedReason($learner),
            category: $score->category === null ? null : ScoreCategory::from($score->category),
            longWords: $score->long_words,
        );
    }

    /**
     * Why a learner with a row of the month has no place (docs/34 §3): blocked by an admin first (the opt-out does not
     * help them back), then opted out of the leagues; null when neither holds (a deleted learner reads nothing here).
     *
     * @return MonthlyMyLine::REASON_*|null
     */
    private function notCountedReason(Learner $learner): ?string
    {
        if ($learner->blocked_at !== null) {
            return MonthlyMyLine::REASON_BLOCKED;
        }

        return $this->leagues->isOptedOut($learner) ? MonthlyMyLine::REASON_OPTED_OUT : null;
    }

    /**
     * A page of one category of a v2 month (docs/32 §5.3, §8.3 - `GET /league/month?category=&after=`): up to `$limit` participants
     * after the cursor, with their competition places, and the cursor of the next page. Not cached (the top is: {@see top()}).
     *
     * @return array{rows: list<BoardRow>, next: BoardCursor|null}
     */
    public function board(LeagueMonth $month, ScoreCategory $category, ?BoardCursor $after, int $limit = 50): array
    {
        return $this->board->page(BoardScope::month($month->id, $category), $after, $limit);
    }

    /**
     * The participants right above and below the learner's row in its category of a v2 month, the learner's place and the points that
     * overtake the next place (null for a leader, and for a row out of the table: not a participant, or a v1 month).
     *
     * `$rank` - the place of the row when the caller has counted it already ({@see rankOf()}): it is not counted again.
     *
     * @return array{above: list<BoardRow>, below: list<BoardRow>, rank: int, next_gap: int|null}|null
     */
    public function neighbours(LeagueMonth $month, LeagueMonthScore $score, int $n = 2, ?int $rank = null): ?array
    {
        if (! self::isV2($month) || ! $score->participant || $score->category === null) {
            return null;
        }

        $n = max(1, min(10, $n));
        $scope = BoardScope::month($month->id, ScoreCategory::from($score->category));
        $around = $this->board->neighbours($scope, new BoardCursor($score->points, $score->long_words, $score->active_days, $score->learner_id), $n, $rank);

        // The gap is read off the rows above; a statement only when all of them share the viewer's place and the list is full.
        $gap = $this->board->gapFromAbove($around['above'], $score->points, $score->long_words, $score->active_days, count($around['above']) < $n);
        if ($gap === false) {
            $gap = $this->board->gapToNext($scope, $score->points, $score->long_words, $score->active_days);
        }

        return [...$around, 'next_gap' => $gap];
    }

    public function scoreOf(LeagueMonth $month, int $learnerId): ?LeagueMonthScore
    {
        /** @var LeagueMonthScore|null $score */
        $score = LeagueMonthScore::query()->where('league_month_id', $month->id)->where('learner_id', $learnerId)->first();

        return $score;
    }

    /**
     * The place of a participant's row: the final rank once the month is closed, else "participants ahead of me" + 1 in
     * the order of the rating (one indexed count).
     */
    public function rankOf(LeagueMonth $month, LeagueMonthScore $score): int
    {
        if ($month->isClosed() && $score->final_rank !== null) {
            return $score->final_rank;
        }

        if (self::isV2($month)) {
            $category = ScoreCategory::from((string) $score->category);
            $count = fn (): int => $this->board->rank(BoardScope::month($month->id, $category), $score->points, $score->long_words, $score->active_days);
            if (! $month->isOpen()) {
                return $count();
            }

            // The place of the tuple, cached a minute (the key holds the tuple: a credit of the viewer reads a new key at once).
            $key = self::RANK_PREFIX.implode(':', [$month->id, $category->value, $score->points, $score->long_words, $score->active_days]);

            return (int) PureCache::store()->remember($key, $this->rankTtl(), $count);
        }

        $ahead = LeagueMonthScore::query()
            ->where('league_month_id', $month->id)
            ->where('participant', true)
            ->where(function ($q) use ($score): void {
                $q->where('points', '>', $score->points)
                    ->orWhere(function ($q) use ($score): void {
                        $q->where('points', $score->points)->where('active_days', '>', $score->active_days);
                    })
                    ->orWhere(function ($q) use ($score): void {
                        $q->where('points', $score->points)->where('active_days', $score->active_days)->where('learner_id', '<', $score->learner_id);
                    });
            })
            ->count();

        return $ahead + 1;
    }

    /**
     * Prize eligibility of a row: a participant with at least `min_active_days` days with points and an account at least
     * `min_account_days` old at the end of the month.
     *
     * @return array{0: bool, 1: int, 2: int} eligible, missing active days, missing account days
     */
    public function eligibility(LeagueMonth $month, LeagueMonthScore $score, Learner $learner): array
    {
        $missingDays = max(0, $this->minActiveDays() - $score->active_days);
        $missingAccount = max(0, $this->minAccountDays() - $this->accountAgeDays($month, $learner));

        return [$score->participant && $missingDays === 0 && $missingAccount === 0, $missingDays, $missingAccount];
    }

    /** Whole days between the learner's local day of registration (league zone) and the last day of the month; never negative. */
    public function accountAgeDays(LeagueMonth $month, Learner $learner): int
    {
        $created = $learner->created_at;
        if ($created === null) {
            return 0;
        }

        $createdDay = CarbonImmutable::instance($created)->setTimezone($this->timezone())->startOfDay();
        $lastDay = $this->firstDayAt($month)->endOfMonth()->startOfDay();

        return max(0, (int) $createdDay->diffInDays($lastDay, false));
    }

    /**
     * The prizes of the month: its own list, or the configured defaults when it has none (`prizes IS NULL`); places
     * without a title are dropped; empty = the prize block is hidden.
     *
     * A v2 month has the prizes of each table (`$category`, Free when null; owner's decision В5: 3 places in Free and 3 in Pro):
     * `prizes` is `{free: [{place, ru, uz}], pro: [...]}`; a plain list there (written before the month knew its categories) is the
     * same list for both tables; a category missing from the object has no prizes. The places run to {@see places()} of the category.
     *
     * @return list<MonthlyPrize>
     */
    public function prizes(LeagueMonth $month, ?ScoreCategory $category = null): array
    {
        $raw = $month->prizes;
        $category = self::isV2($month) ? ($category ?? ScoreCategory::Free) : null;
        if ($category !== null && is_array($raw) && ! array_is_list($raw)) {
            $raw = is_array($raw[$category->value] ?? null) ? $raw[$category->value] : [];
        }
        $places = $this->places($category);
        if ($raw === null) {
            $raw = [];
            foreach ((array) config('leagues.monthly.prizes', []) as $place => $titles) {
                $titles = is_array($titles) ? $titles : [];
                $raw[] = ['place' => (int) $place, 'ru' => (string) ($titles['ru'] ?? ''), 'uz' => (string) ($titles['uz'] ?? '')];
            }
        }

        $prizes = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $place = (int) ($item['place'] ?? 0);
            $ru = trim((string) ($item['ru'] ?? ''));
            $uz = trim((string) ($item['uz'] ?? ''));
            if ($place < 1 || $place > $places || ($ru === '' && $uz === '')) {
                continue;
            }
            $prizes[$place] = new MonthlyPrize($place, $ru, $uz);
        }
        ksort($prizes);

        return array_values($prizes);
    }

    /** The previous month for the Mini App: its winners once confirmed, and the learner's own final place. */
    public function lastMonth(Learner $learner, CarbonImmutable $now): ?LastMonthView
    {
        $month = $this->previousMonth($now);
        if ($month === null) {
            return null;
        }

        $winners = [];
        if ($month->isConfirmed()) {
            $rows = DB::table('league_month_candidates as c')
                ->leftJoin('learners as l', 'l.id', '=', 'c.learner_id')
                ->where('c.league_month_id', $month->id)
                ->where('c.status', LeagueMonthCandidateStatus::Winner->value)
                ->orderBy('c.place')
                ->get(['c.place', 'c.points', 'c.category', 'l.first_name', 'l.deleted_at']);
            foreach ($rows as $row) {
                $winners[] = [
                    'place' => (int) $row->place,
                    'name' => $this->displayName($row->first_name ?? null, $row->deleted_at ?? null),
                    'points' => (int) $row->points,
                    // A v2 month: the two tables share the list, every winner with the table of their place (docs/32 §7).
                    ...(self::isV2($month) ? ['category' => is_string($row->category ?? null) ? $row->category : null] : []),
                ];
            }
        }

        $score = $this->scoreOf($month, $learner->id);
        $me = $score !== null && $score->participant && $score->final_rank !== null
            ? ['rank' => $score->final_rank, 'points' => $score->points, ...(self::isV2($month) ? ['category' => $score->category] : [])]
            : null;

        return new LastMonthView($month->starts_on->toDateString(), $month->ends_on->toDateString(), $month->status, $winners, $me, (int) $month->rules_version);
    }

    /** @return array{daily_cap: int, min_active_days: int, min_account_days: int, places: int, top: int} */
    public function rules(): array
    {
        return [
            'daily_cap' => $this->dailyCap(),
            'min_active_days' => $this->minActiveDays(),
            'min_account_days' => $this->minAccountDays(),
            'places' => $this->places(),
            'top' => $this->topSize(),
        ];
    }

    /**
     * The anti-abuse facts of a row, as the administrator sees them next to a candidate. Nothing is decided here: the
     * `flags` only point. Thresholds — `leagues.monthly.signals`.
     *
     * @return array<string, mixed>
     */
    public function signals(LeagueMonth $month, LeagueMonthScore $score, Learner $learner): array
    {
        $cfg = (array) config('leagues.monthly.signals', []);
        $accuracy = $score->reviews > 0 ? round($score->correct / $score->reviews, 3) : null;
        $avgMs = $score->reviews > 0 ? intdiv($score->time_spent_ms, $score->reviews) : null;
        $avgDay = $score->active_days > 0 ? $score->raw_points / $score->active_days : 0.0;
        $spike = $avgDay > 0 ? round($score->max_day_points / $avgDay, 2) : null;
        $age = $this->accountAgeDays($month, $learner);

        $flags = [];
        if ($accuracy !== null && $score->reviews >= 50 && $accuracy < (float) ($cfg['min_accuracy'] ?? 0.55)) {
            $flags[] = 'low_accuracy';
        }
        if ($accuracy !== null && $score->reviews >= 50 && $accuracy > (float) ($cfg['max_accuracy'] ?? 0.98)) {
            $flags[] = 'high_accuracy';
        }
        if ($avgMs !== null && $avgMs < (int) ($cfg['min_ms_per_review'] ?? 1500)) {
            $flags[] = 'too_fast';
        }
        if ($age < (int) ($cfg['young_account_days'] ?? 30)) {
            $flags[] = 'young_account';
        }
        if ($spike !== null && $score->active_days >= 5 && $spike >= (float) ($cfg['spike_ratio'] ?? 3.0)) {
            $flags[] = 'spiky';
        }
        if ($score->capped_days >= max(1, (int) ($cfg['capped_days'] ?? 5))) {
            $flags[] = 'capped_often';
        }

        return [
            'active_days' => $score->active_days,
            'reviews' => $score->reviews,
            'correct' => $score->correct,
            'accuracy' => $accuracy,
            'avg_ms_per_review' => $avgMs,
            'new_words' => $score->new_words,
            'goal_days' => $score->goal_days,
            'game_points' => $score->game_points,
            'raw_points' => $score->raw_points,
            'capped_days' => $score->capped_days,
            'max_day_points' => $score->max_day_points,
            'spike_ratio' => $spike,
            'account_age_days' => $age,
            'overall_rank' => $score->final_rank,
            'flags' => $flags,
        ];
    }

    /**
     * The learner's days of the month with their points (the review's "points by day"; at most 31 rows).
     *
     * @return list<array{date: string, reviews: int, correct: int, new_words: int, goal_met: bool, game_points: int, time_spent_ms: int, points: int, counted: int}>
     */
    public function dailyBreakdown(LeagueMonth $month, int $learnerId): array
    {
        $cap = $this->dailyCap();
        $rows = DB::table('learner_daily_stats')
            ->where('learner_id', $learnerId)
            ->where('date', '>=', StoredDate::of($month->starts_on->toDateString()))
            ->where('date', '<=', StoredDate::of($month->ends_on->toDateString()))
            ->orderBy('date')
            ->get(['date', 'reviews_count', 'correct_count', 'new_words_count', 'goal_met_at', 'game_points', 'time_spent_ms']);

        $days = [];
        foreach ($rows as $row) {
            $points = LeaguePoints::ofCounts((int) $row->reviews_count, (int) $row->correct_count, (int) $row->new_words_count, $row->goal_met_at !== null, (int) $row->game_points);
            $days[] = [
                'date' => substr((string) $row->date, 0, 10),
                'reviews' => (int) $row->reviews_count,
                'correct' => (int) $row->correct_count,
                'new_words' => (int) $row->new_words_count,
                'goal_met' => $row->goal_met_at !== null,
                'game_points' => (int) $row->game_points,
                'time_spent_ms' => (int) $row->time_spent_ms,
                'points' => $points,
                'counted' => $cap > 0 ? min($cap, $points) : $points,
            ];
        }

        return $days;
    }

    /**
     * The learner's rating days of a v2 month with both counts as stored (the admin's "points by day" of S15 and of the review of a
     * candidate; at most 31 rows by the unique `(learner_id, rating_day)`). The journal of a day - `score_credits` of
     * (learner, rating_day), the same unique index - is what the correction voids (ScoreCorrectionService).
     *
     * @return list<array{rating_day: string, month_points: int, month_new: int, month_review: int, month_long: int, capped: bool, week_points: int, raw_points: int, new_count: int, review_count: int, long_count: int, category: string, revision: int}>
     */
    public function scoreDays(LeagueMonth $month, int $learnerId): array
    {
        $rows = DB::table('learner_score_days')
            ->where('learner_id', $learnerId)
            ->where('rating_day', '>=', StoredDate::of($month->starts_on->toDateString()))
            ->where('rating_day', '<=', StoredDate::of($month->ends_on->toDateString()))
            ->orderBy('rating_day')
            ->get(['rating_day', 'month_points', 'month_new', 'month_review', 'month_long', 'month_capped_at', 'week_points', 'raw_points', 'new_count', 'review_count', 'long_count', 'month_category', 'revision']);

        $days = [];
        foreach ($rows as $row) {
            $days[] = [
                'rating_day' => substr((string) $row->rating_day, 0, 10),
                'month_points' => (int) $row->month_points,
                'month_new' => (int) $row->month_new,
                'month_review' => (int) $row->month_review,
                'month_long' => (int) $row->month_long,
                'capped' => $row->month_capped_at !== null,
                'week_points' => (int) $row->week_points,
                'raw_points' => (int) $row->raw_points,
                'new_count' => (int) $row->new_count,
                'review_count' => (int) $row->review_count,
                'long_count' => (int) $row->long_count,
                'category' => (string) $row->month_category,
                'revision' => (int) $row->revision,
            ];
        }

        return $days;
    }

    // --- closing -----------------------------------------------------------------------------

    /**
     * Closes a month whose close is due — see the class comment. Returns whether THIS call made the step to
     * `pending_review`; a month already closed, not due yet, or taken by a parallel closer answers false.
     */
    public function closeMonth(LeagueMonth $month, CarbonImmutable $now): bool
    {
        if (! $this->isDue($month, $now) || ! $this->beginClosing($month)) {
            return false;
        }

        $closed = DB::transaction(function () use ($month, $now): bool {
            /** @var LeagueMonth|null $locked */
            $locked = LeagueMonth::query()->whereKey($month->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== LeagueMonthStatus::Closing->value) {
                return false;
            }

            // The authoritative numbers: everybody, from the committed day rows; no refresh can be writing (the barrier).
            $this->upsertScores($locked, $now, null, null);
            $this->writeFinalRanks($locked);
            $this->snapshotCandidates($locked);

            $locked->forceFill([
                'status' => LeagueMonthStatus::PendingReview->value,
                'closed_at' => $now,
                'refreshed_at' => $now,
                'participants_count' => $this->participantsCount($locked),
            ])->save();
            $month->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if (! $closed) {
            return false;
        }

        PureCache::forgetMany(self::topKeys($month->id));
        PendingLeagueMonthsBadge::forget();
        $this->events->record(ProductEventName::LeagueMonthClosed, [
            'month_id' => $month->id,
            'starts_on' => $month->starts_on->toDateString(),
            'participants' => $month->participants_count,
            'candidates' => LeagueMonthCandidate::query()->where('league_month_id', $month->id)->count(),
        ]);

        return true;
    }

    /** Closes every month whose close is due and that is not closed yet (the scheduler; a missed run is caught up). */
    public function closeDue(CarbonImmutable $now): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        /** @var Collection<int, LeagueMonth> $due */
        $due = LeagueMonth::query()
            ->whereIn('status', [LeagueMonthStatus::Open->value, LeagueMonthStatus::Closing->value])
            ->where('starts_on', '<', StoredDate::of($this->monthStart($now)->toDateString()))
            ->orderBy('starts_on')
            ->get();

        $closed = 0;
        foreach ($due as $month) {
            if ($this->closeMonth($month, $now)) {
                $closed++;
            }
        }

        if ($closed > 0) {
            // The reconcile of "all time" after the close (docs/32 §7): every learner from the same source. The close changes no sum;
            // this catches whatever the incremental runs missed. The months are closed already - a failure here is only reported.
            try {
                $this->totals->reconcileAll($now);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $closed;
    }

    /**
     * The learner's region changed (docs/35 §2.4, R6): after the commit (at once outside a transaction) -
     *
     *   the month   a period of an OPEN month fixed without a region (the month started before any region was chosen) takes the
     *               region now, once, and the learner's row of that month is recounted: the first choice counts at once, not only
     *               from the next month. A period with a region keeps it - a change moves the NEXT month only, the points stay where
     *               they were earned; a reset to "not given" leaves the fixed region too (a reset plus a new choice must not move a month);
     *   all time    the learner's totals are recomputed: they follow the current region (none after a reset).
     *
     * Reported, never thrown: the region is saved already.
     */
    public function regionChanged(Learner $learner, ?CarbonImmutable $now = null): void
    {
        $this->totals->learnerChanged($learner->id, $now);

        DB::afterCommit(function () use ($learner, $now): void {
            try {
                $region = $learner->region_code;
                // Only a month of the word points v2 has tables of a region; with the points off there is none to fill.
                if (! $this->enabled() || ! $this->totals->enabled() || $region === null) {
                    return;
                }

                $now ??= CarbonImmutable::now();
                /** @var Collection<int, LeagueMonth> $open */
                $open = LeagueMonth::query()->where('status', LeagueMonthStatus::Open->value)->where('rules_version', '>=', 2)->get();
                if ($open->isEmpty()) {
                    return;
                }

                $filled = DB::table('learner_score_periods')
                    ->where('learner_id', $learner->id)
                    ->where('period', 'month')
                    ->whereNull('region_code')
                    ->whereIn('starts_on', $open->map(static fn (LeagueMonth $m): string => StoredDate::of($m->starts_on->toDateString()))->all())
                    ->update(['region_code' => $region]);
                if ($filled === 0) {
                    return;
                }

                foreach ($open as $month) {
                    if ($this->refreshLearner($month, $learner, $now)) {
                        PureCache::forgetMany(self::topKeys($month->id));
                    }
                }
            } catch (Throwable $e) {
                report($e);
                Log::warning('league.month_region_refresh_failed', ['learner_id' => $learner->id, 'exception' => $e::class, 'error' => $e->getMessage()]);
            }
        });
    }

    /** The key of the cached top: the v1 table of a month, or one category of a v2 month. */
    public static function topKey(int $monthId, ?ScoreCategory $category = null): string
    {
        return $category === null ? self::TOP_PREFIX.$monthId : self::TOP_PREFIX_V2.$monthId.':'.$category->value;
    }

    /**
     * Every key of the tops of a month (v1, both categories of v2 and their tables of every region) and of their numbers of participants:
     * what a write to the month drops.
     *
     * @return list<string>
     */
    public static function topKeys(int $monthId): array
    {
        $keys = [
            self::topKey($monthId), self::topKey($monthId, ScoreCategory::Free), self::topKey($monthId, ScoreCategory::Pro),
            self::countKey($monthId), self::countKey($monthId, ScoreCategory::Free), self::countKey($monthId, ScoreCategory::Pro),
        ];
        // The tables of the regions (docs/35 §2.4): their tops and numbers of participants go with the tops of the month - one DEL.
        foreach (ScoreCategory::cases() as $category) {
            foreach (Regions::codes() as $region) {
                array_push($keys, ...BoardKeys::monthRegion($monthId, $category, $region)->written());
            }
        }

        return $keys;
    }

    /** The key of the cached number of participants of a table of the month ({@see COUNT_PREFIX}). */
    public static function countKey(int $monthId, ?ScoreCategory $category = null): string
    {
        return self::COUNT_PREFIX.$monthId.':'.($category === null ? 'all' : $category->value);
    }

    public function dailyCap(): int
    {
        return max(0, (int) config('leagues.monthly.daily_cap', 300));
    }

    /**
     * The prize places: of the one table of a v1 month (`monthly.places`), or of a category of a v2 month
     * (`monthly.categories.{free|pro}.places`, owner's decision В5: 3 and 3).
     */
    public function places(?ScoreCategory $category = null): int
    {
        if ($category !== null) {
            return max(1, (int) config("leagues.monthly.categories.{$category->value}.places", 3));
        }

        return max(1, (int) config('leagues.monthly.places', 3));
    }

    /**
     * How many winners above {@see places()} a shared prize place may add in a category of a v2 month (docs/32 §7, published ahead):
     * everybody who shares a prize place gets that place and the same prize while the winners stay within `places + tie_reserve`;
     * beyond it the confirmation stops and the administrator decides (MonthlyRatingReviewService::confirm).
     */
    public function tieReserve(ScoreCategory $category): int
    {
        return max(0, (int) config("leagues.monthly.categories.{$category->value}.tie_reserve", 1));
    }

    public function topSize(): int
    {
        return max(1, (int) config('leagues.monthly.top', 50));
    }

    public function minActiveDays(): int
    {
        return max(0, (int) config('leagues.monthly.min_active_days', 10));
    }

    public function minAccountDays(): int
    {
        return max(0, (int) config('leagues.monthly.min_account_days', 14));
    }

    public function candidateCount(?ScoreCategory $category = null): int
    {
        return max($this->places($category), (int) config('leagues.monthly.candidates', 10));
    }

    /** A display name for the Mini App: the first name, or null (deleted account, empty name). */
    public function displayName(mixed $firstName, mixed $deletedAt): ?string
    {
        return $deletedAt === null && is_string($firstName) && $firstName !== '' ? $firstName : null;
    }

    // --- internals ---------------------------------------------------------------------------

    private function firstDayAt(LeagueMonth $month): CarbonImmutable
    {
        $first = CarbonImmutable::createFromFormat('!Y-m-d', $month->starts_on->toDateString(), $this->timezone());
        if (! $first instanceof CarbonImmutable) {
            throw new RuntimeException("League month {$month->id} has no valid first day.");
        }

        return $first;
    }

    /** The month row under `FOR SHARE` when it is still open, else null. */
    private function openForShare(LeagueMonth $month): ?LeagueMonth
    {
        /** @var LeagueMonth|null $locked */
        $locked = LeagueMonth::query()->whereKey($month->id)->sharedLock()->first();

        return $locked !== null && $locked->isOpen() ? $locked : null;
    }

    /** The barrier: `open → closing` under the month row lock; a month already `closing` passes, a closed one returns false. */
    private function beginClosing(LeagueMonth $month): bool
    {
        return DB::transaction(function () use ($month): bool {
            /** @var LeagueMonth|null $locked */
            $locked = LeagueMonth::query()->whereKey($month->id)->lockForUpdate()->first();
            if ($locked === null || $locked->isClosed()) {
                return false;
            }

            if ($locked->isOpen()) {
                $locked->forceFill(['status' => LeagueMonthStatus::Closing->value])->save();
            }
            $month->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /** `Y-m-d` from which the incremental refresh takes its learners: `refresh_recent_days` back from today, inside the month. */
    private function recentFrom(LeagueMonth $month, CarbonImmutable $now): string
    {
        $days = max(1, (int) config('leagues.monthly.refresh_recent_days', 3));
        $from = $now->setTimezone($this->timezone())->startOfDay()->subDays($days - 1);
        $first = $this->firstDayAt($month);

        return ($from->isBefore($first) ? $first : $from)->toDateString();
    }

    /**
     * ONE statement that recomputes the rows of a set of learners from their day rows of the month and writes only what
     * changed: `INSERT … SELECT … ON CONFLICT (month, learner) DO UPDATE … WHERE <any column differs>`. The set: one
     * learner (`$onlyLearnerId`), the learners with a row dated on or after `$recentFrom`, or everybody with a row in
     * the month. Bindings only — the driver-specific pieces are constants (`least` / `min`, the boolean literals, the
     * JSON path of the opt-out flag). A participant is whoever the weekly league takes too (docs/34 §3): not deleted, not
     * blocked, not opted out — the onboarding in the bot is not asked for (the Mini App lets a learner study without it).
     *
     * @param  string|null  $recentFrom  `Y-m-d`
     */
    private function upsertScores(LeagueMonth $month, CarbonImmutable $now, ?int $onlyLearnerId, ?string $recentFrom): void
    {
        if (self::isV2($month)) {
            $this->upsertScoresV2($month, $now, $onlyLearnerId, $recentFrom);

            return;
        }

        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $least = $pgsql ? 'least' : 'min';
        [$true, $false] = $pgsql ? ['true', 'false'] : ['1', '0'];
        // PostgreSQL cannot infer the type of a bare parameter in the select list of an INSERT … SELECT.
        $typed = static fn (string $type): string => $pgsql ? "?::{$type}" : '?';
        $notOptedOut = $pgsql
            ? "coalesce(l.meta->>'leagues_opt_out', 'false') <> 'true'"
            : "coalesce(json_extract(l.meta, '$.leagues_opt_out'), 0) <> 1";

        [$dayPoints, $weights] = LeaguePoints::dayExpression('s');
        $cap = $this->dailyCap();
        $stamp = (string) (new LeagueMonthScore)->fromDateTime($now);
        $from = StoredDate::of($month->starts_on->toDateString());
        $to = StoredDate::of($month->ends_on->toDateString());

        $bindings = [$month->id, $stamp, $stamp];

        if ($cap > 0) {
            $counted = "{$least}(d.pts, ?)";
            $cappedDays = 'sum(case when d.pts > ? then 1 else 0 end)';
            array_push($bindings, $cap, $cap);
        } else {
            $counted = 'd.pts';
            $cappedDays = '0';
        }

        array_push($bindings, ...$weights);
        array_push($bindings, $from, $to);

        $scope = '';
        if ($onlyLearnerId !== null) {
            $scope = ' and s.learner_id = ?';
            $bindings[] = $onlyLearnerId;
        } elseif ($recentFrom !== null) {
            $scope = ' and s.learner_id in (select r.learner_id from learner_daily_stats r where r.date >= ? and r.date <= ?)';
            array_push($bindings, StoredDate::of($recentFrom), $to);
        }

        $columns = ['points', 'raw_points', 'active_days', 'capped_days', 'max_day_points', 'reviews', 'correct', 'new_words', 'goal_days', 'game_points', 'time_spent_ms', 'participant'];
        $set = implode(', ', array_map(static fn (string $c): string => "{$c} = excluded.{$c}", $columns)).', updated_at = excluded.updated_at';
        $changed = implode(' or ', array_map(static fn (string $c): string => "league_month_scores.{$c} <> excluded.{$c}", $columns));

        DB::affectingStatement(
            'insert into league_month_scores (league_month_id, learner_id, points, raw_points, active_days, capped_days, max_day_points, '
            .'reviews, correct, new_words, goal_days, game_points, time_spent_ms, participant, final_rank, created_at, updated_at) '
            ."select {$typed('bigint')}, a.learner_id, a.points, a.raw_points, a.active_days, a.capped_days, a.max_day_points, a.reviews, a.correct, "
            .'a.new_words, a.goal_days, a.game_points, a.time_spent_ms, '
            ."case when l.deleted_at is null and l.blocked_at is null and {$notOptedOut} then {$true} else {$false} end, "
            ."null, {$typed('timestamp')}, {$typed('timestamp')} "
            .'from ('
            ."select d.learner_id, sum({$counted}) as points, sum(d.pts) as raw_points, "
            .'sum(case when d.pts > 0 then 1 else 0 end) as active_days, '
            ."{$cappedDays} as capped_days, max(d.pts) as max_day_points, "
            .'sum(d.reviews_count) as reviews, sum(d.correct_count) as correct, sum(d.new_words_count) as new_words, '
            .'sum(case when d.goal_met_at is not null then 1 else 0 end) as goal_days, '
            .'sum(d.game_points) as game_points, sum(d.time_spent_ms) as time_spent_ms '
            .'from ('
            .'select s.learner_id, s.reviews_count, s.correct_count, s.new_words_count, s.goal_met_at, s.game_points, s.time_spent_ms, '
            ."{$dayPoints} as pts from learner_daily_stats s where s.date >= ? and s.date <= ?{$scope}"
            .') d group by d.learner_id'
            .') a join learners l on l.id = a.learner_id where l.id is not null '
            ."on conflict (league_month_id, learner_id) do update set {$set} where {$changed}",
            $bindings,
        );
    }

    /**
     * The v2 source (docs/32 §5.2, §12.3 S9): ONE statement as in v1, from the rating days of the month (`learner_score_days`, league
     * zone - no local dates, no lagging evenings):
     *
     *   points      = SUM(month_points) - the monthly count already holds the limits and the day cap of the category;
     *   active_days = the days with month points; capped_days = the days the count reached the cap; raw_points / max_day_points = the
     *                 word points without the limits (the review's signals);
     *   long_words  = the DISTINCT lexemes with a long repetition that added month points (not voided) - from the journal
     *                 `score_credits` (one lexeme can be paid a long repetition two or three times a month: 7, then ≥ 17 days);
     *   category    = `month_category` of the days (fixed once per learner and month: every day of the month has the same);
     *   region_code = the region of the learner's month (`learner_score_periods.region_code`, docs/35 §2.4): fixed at its first credit,
     *                 an empty one filled once by the first region chosen ({@see regionChanged()});
     *   reviews / correct / new words / goal days / game points / time - the admin's signals, from `learner_daily_stats` by the local
     *                 dates of the month, as in v1 (approximate, they only point).
     * Who is a participant - as in v1 (not deleted, not blocked, not opted out; the onboarding is not asked for, docs/34 §3); a learner
     * with no credit in the month has no rating day and no row. The three sources are grouped subqueries joined by learner, each scoped
     * like the rows: one learner, the learners with a rating day in the last `refresh_recent_days`, or everybody of the month.
     *
     * @param  string|null  $recentFrom  `Y-m-d`
     */
    private function upsertScoresV2(LeagueMonth $month, CarbonImmutable $now, ?int $onlyLearnerId, ?string $recentFrom): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        [$true, $false] = $pgsql ? ['true', 'false'] : ['1', '0'];
        $typed = static fn (string $type): string => $pgsql ? "?::{$type}" : '?';
        $notOptedOut = $pgsql
            ? "coalesce(l.meta->>'leagues_opt_out', 'false') <> 'true'"
            : "coalesce(json_extract(l.meta, '$.leagues_opt_out'), 0) <> 1";

        $stamp = (string) (new LeagueMonthScore)->fromDateTime($now);
        $from = StoredDate::of($month->starts_on->toDateString());
        $to = StoredDate::of($month->ends_on->toDateString());

        // The same scope for each of the three sources (alias `x`).
        $scope = static function (string $alias) use ($onlyLearnerId, $recentFrom, $to): array {
            if ($onlyLearnerId !== null) {
                return [" and {$alias}.learner_id = ?", [$onlyLearnerId]];
            }
            if ($recentFrom !== null) {
                return [" and {$alias}.learner_id in (select r.learner_id from learner_score_days r where r.rating_day >= ? and r.rating_day <= ?)", [StoredDate::of($recentFrom), $to]];
            }

            return ['', []];
        };
        [$daysScope, $daysBindings] = $scope('s');
        [$longScope, $longBindings] = $scope('c');
        [$statsScope, $statsBindings] = $scope('t');

        $columns = ['category', 'region_code', 'points', 'raw_points', 'active_days', 'capped_days', 'max_day_points', 'long_words', 'reviews', 'correct', 'new_words', 'goal_days', 'game_points', 'time_spent_ms', 'participant'];
        $set = implode(', ', array_map(static fn (string $c): string => "{$c} = excluded.{$c}", $columns)).', updated_at = excluded.updated_at';
        // `category` may be NULL on a row written before (never in a v2 month, but `<>` with NULL is never true), `region_code` is NULL
        // without a region: compare both null-safely.
        $changed = implode(' or ', array_map(
            static fn (string $c): string => in_array($c, ['category', 'region_code'], true)
                ? ($pgsql ? "league_month_scores.{$c} is distinct from excluded.{$c}" : "league_month_scores.{$c} is not excluded.{$c}")
                : "league_month_scores.{$c} <> excluded.{$c}",
            $columns,
        ));

        DB::affectingStatement(
            'insert into league_month_scores (league_month_id, learner_id, category, region_code, points, raw_points, active_days, capped_days, max_day_points, '
            .'long_words, reviews, correct, new_words, goal_days, game_points, time_spent_ms, participant, final_rank, created_at, updated_at) '
            ."select {$typed('bigint')}, a.learner_id, a.category, p.region_code, a.points, a.raw_points, a.active_days, a.capped_days, a.max_day_points, "
            .'coalesce(c.long_words, 0), coalesce(t.reviews, 0), coalesce(t.correct, 0), coalesce(t.new_words, 0), coalesce(t.goal_days, 0), '
            .'coalesce(t.game_points, 0), coalesce(t.time_spent_ms, 0), '
            ."case when l.deleted_at is null and l.blocked_at is null and {$notOptedOut} then {$true} else {$false} end, "
            ."null, {$typed('timestamp')}, {$typed('timestamp')} "
            .'from ('
            .'select s.learner_id, max(s.month_category) as category, sum(s.month_points) as points, sum(s.raw_points) as raw_points, '
            .'sum(case when s.month_points > 0 then 1 else 0 end) as active_days, count(s.month_capped_at) as capped_days, '
            .'max(s.raw_points) as max_day_points '
            ."from learner_score_days s where s.rating_day >= ? and s.rating_day <= ?{$daysScope} group by s.learner_id"
            .') a '
            .'join learners l on l.id = a.learner_id '
            // The region the learner's month is fixed in (docs/35 §2.4): the period row by its primary key.
            ."left join learner_score_periods p on p.learner_id = a.learner_id and p.period = 'month' and p.starts_on = ? "
            .'left join ('
            .'select c.learner_id, count(distinct c.lexeme_id) as long_words from score_credits c '
            ."where c.rating_day >= ? and c.rating_day <= ? and c.kind = 'long' and c.month_points > 0 and c.voided_at is null{$longScope} "
            .'group by c.learner_id'
            .') c on c.learner_id = a.learner_id '
            .'left join ('
            .'select t.learner_id, sum(t.reviews_count) as reviews, sum(t.correct_count) as correct, sum(t.new_words_count) as new_words, '
            .'sum(case when t.goal_met_at is not null then 1 else 0 end) as goal_days, sum(t.game_points) as game_points, '
            .'sum(t.time_spent_ms) as time_spent_ms '
            ."from learner_daily_stats t where t.date >= ? and t.date <= ?{$statsScope} group by t.learner_id"
            .') t on t.learner_id = a.learner_id '
            // sqlite: an INSERT … SELECT with joins needs a WHERE before ON CONFLICT (the parser would read it as a join's ON).
            .'where a.learner_id is not null '
            ."on conflict (league_month_id, learner_id) do update set {$set} where {$changed}",
            [
                $month->id, $stamp, $stamp,
                $from, $to, ...$daysBindings,
                $from,
                $from, $to, ...$longBindings,
                $from, $to, ...$statsBindings,
            ],
        );
    }

    /**
     * The scheduled half of "the first region fills the open month" (docs/35 §2.4, docs/32 §12.11): the month periods of the learners
     * refreshed now (`$recentFrom`, or everybody of the month) that are still without a region while the learner has one now get it.
     * {@see regionChanged()} does this after the commit of the first choice, but a first credit of the month committed in the same
     * instant (it read `learners` before the choice committed, its period row was not there yet for the fill) leaves the period empty:
     * this run closes it within 10 minutes. Only an empty region is filled - a fixed one never moves (docs/35 §2.4).
     *
     * One statement, nothing written in the usual case: the set along `learner_score_days (rating_day)`, the periods by their primary
     * key, the learners by theirs. A v1 month has no regions.
     */
    private function fillMissingRegions(LeagueMonth $month, ?string $recentFrom): int
    {
        if (! self::isV2($month)) {
            return 0;
        }

        $from = StoredDate::of($month->starts_on->toDateString());

        return DB::update(
            'update learner_score_periods set region_code = (select l.region_code from learners l where l.id = learner_score_periods.learner_id) '
            ."where learner_score_periods.period = 'month' and learner_score_periods.starts_on = ? and learner_score_periods.region_code is null "
            .'and learner_score_periods.learner_id in (select r.learner_id from learner_score_days r where r.rating_day >= ? and r.rating_day <= ?) '
            .'and exists (select 1 from learners l where l.id = learner_score_periods.learner_id and l.region_code is not null)',
            [$from, $recentFrom !== null ? StoredDate::of($recentFrom) : $from, StoredDate::of($month->ends_on->toDateString())],
        );
    }

    /** `participants_count` and `refreshed_at` of an open month after a refresh — its own short statement, no lock held with it. */
    private function stampRefresh(LeagueMonth $month, CarbonImmutable $now): void
    {
        $count = $this->participantsCount($month);
        LeagueMonth::query()
            ->whereKey($month->id)
            ->where('status', LeagueMonthStatus::Open->value)
            ->update(['participants_count' => $count, 'refreshed_at' => $now, 'updated_at' => $now]);
        $month->participants_count = $count;
    }

    /**
     * {@see participantsCount()} for the screens, cached with the top of the same table ({@see COUNT_PREFIX}). Redis returns a
     * top-level number as a string (docs/19 §3 п. 11) - hence the cast.
     */
    public function cachedParticipantsCount(LeagueMonth $month, ?ScoreCategory $category = null): int
    {
        $category = self::isV2($month) ? $category : null;

        return (int) PureCache::store()->remember(
            self::countKey($month->id, $category),
            max(1, (int) config('leagues.monthly.top_cache_seconds', 120)),
            fn (): int => $this->participantsCount($month, $category),
        );
    }

    /** The participants of the month (both tables of a v2 month), or of one category of a v2 month. */
    public function participantsCount(LeagueMonth $month, ?ScoreCategory $category = null): int
    {
        return LeagueMonthScore::query()
            ->where('league_month_id', $month->id)
            ->when($category !== null, static fn ($q) => $q->where('category', $category?->value))
            ->where('participant', true)
            ->count();
    }

    /**
     * `final_rank` of every participant by the order of the rating (a window function); non-participants get NULL. A v2 month ranks
     * each category on its own with the COMPETITION rank (`RANK() OVER (PARTITION BY category ORDER BY points desc, long_words desc,
     * active_days desc)`, docs/32 §7): a shared place is one place.
     */
    private function writeFinalRanks(LeagueMonth $month): void
    {
        if (self::isV2($month)) {
            $window = 'rank() over (partition by category order by points desc, long_words desc, active_days desc)';
            if (DB::connection()->getDriverName() === 'pgsql') {
                // One pass: the window once, joined by id (a correlated subquery would recompute it per row).
                DB::update(
                    'update league_month_scores as s set final_rank = r.rn from ('
                    ."select id, {$window} as rn from league_month_scores where league_month_id = ? and participant = true"
                    .') r where s.id = r.id and s.final_rank is distinct from r.rn',
                    [$month->id],
                );
                DB::update('update league_month_scores set final_rank = null where league_month_id = ? and participant = false and final_rank is not null', [$month->id]);

                return;
            }

            DB::update(
                'update league_month_scores set final_rank = (select r.rn from ('
                ."select id, {$window} as rn from league_month_scores where league_month_id = ? and participant = 1"
                .') r where r.id = league_month_scores.id) where league_month_id = ?',
                [$month->id, $month->id],
            );

            return;
        }

        $order = 'order by points desc, active_days desc, learner_id asc';

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::update(
                'update league_month_scores as s set final_rank = r.rn from ('
                ."select id, row_number() over ({$order}) as rn from league_month_scores where league_month_id = ? and participant = true"
                .') r where s.id = r.id',
                [$month->id],
            );
            DB::update('update league_month_scores set final_rank = null where league_month_id = ? and participant = false', [$month->id]);

            return;
        }

        DB::update(
            'update league_month_scores set final_rank = (select r.rn from ('
            ."select id, row_number() over ({$order}) as rn from league_month_scores where league_month_id = ? and participant = 1"
            .') r where r.id = league_month_scores.id) where league_month_id = ?',
            [$month->id, $month->id],
        );
    }

    /**
     * Freezes the best `candidates` eligible participants with their signals. Any candidate rows of the month (an
     * interrupted earlier close) are replaced: the month is `closing`, nobody has reviewed anything yet.
     */
    private function snapshotCandidates(LeagueMonth $month): void
    {
        LeagueMonthCandidate::query()->where('league_month_id', $month->id)->delete();

        if (self::isV2($month)) {
            foreach (ScoreCategory::cases() as $category) {
                $this->snapshotCategoryCandidates($month, $category);
            }

            return;
        }

        // Eligible by the account's age: registered on a local day at least `min_account_days` before the last day of the month.
        $cutoff = $this->firstDayAt($month)->endOfMonth()->startOfDay()->subDays($this->minAccountDays())->addDay()->utc();

        /** @var Collection<int, LeagueMonthScore> $scores */
        $scores = LeagueMonthScore::query()
            ->with('learner')
            ->join('learners as l', 'l.id', '=', 'league_month_scores.learner_id')
            ->where('league_month_scores.league_month_id', $month->id)
            ->where('league_month_scores.participant', true)
            ->where('league_month_scores.active_days', '>=', $this->minActiveDays())
            ->where('l.created_at', '<', $cutoff->format('Y-m-d H:i:s'))
            ->orderByDesc('league_month_scores.points')
            ->orderByDesc('league_month_scores.active_days')
            ->orderBy('league_month_scores.learner_id')
            ->limit($this->candidateCount())
            ->select('league_month_scores.*')
            ->get();

        $rank = 0;
        $stamp = $month->freshTimestamp();
        $rows = [];
        foreach ($scores as $score) {
            $learner = $score->learner;
            if ($learner === null) {
                continue;
            }
            $rank++;
            $rows[] = [
                'league_month_id' => $month->id,
                'learner_id' => $score->learner_id,
                'rank' => $rank,
                'points' => $score->points,
                'active_days' => $score->active_days,
                'signals' => json_encode($this->signals($month, $score, $learner), JSON_THROW_ON_ERROR),
                'status' => LeagueMonthCandidateStatus::Candidate->value,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];
        }

        if ($rows !== []) {
            LeagueMonthCandidate::query()->insert($rows);
        }
    }

    /**
     * The candidates of one category of a v2 month (docs/32 §7): the eligible participants (`min_active_days`, the account's age - the
     * v1 thresholds) whose COMPETITION rank among the eligible ones is within {@see candidateCount()} - everybody who shares the last
     * place goes in, so the frozen list may be longer. `rank` of a candidate is that rank: equal tuples - equal ranks.
     *
     * Two statements per category: the tuple at the boundary (`offset candidates − 1`), then everybody at least as good as it - both
     * an index range of `league_month_scores_category_standings_idx` (the eligibility filter is checked on the rows read).
     */
    private function snapshotCategoryCandidates(LeagueMonth $month, ScoreCategory $category): void
    {
        $cutoff = $this->firstDayAt($month)->endOfMonth()->startOfDay()->subDays($this->minAccountDays())->addDay()->utc();
        $eligible = fn () => LeagueMonthScore::query()
            ->join('learners as l', 'l.id', '=', 'league_month_scores.learner_id')
            ->where('league_month_scores.league_month_id', $month->id)
            ->where('league_month_scores.category', $category->value)
            ->where('league_month_scores.participant', true)
            ->where('league_month_scores.active_days', '>=', $this->minActiveDays())
            ->where('l.created_at', '<', $cutoff->format('Y-m-d H:i:s'))
            ->orderByDesc('league_month_scores.points')
            ->orderByDesc('league_month_scores.long_words')
            ->orderByDesc('league_month_scores.active_days')
            ->orderBy('league_month_scores.learner_id');

        /** @var LeagueMonthScore|null $boundary */
        $boundary = $eligible()->offset($this->candidateCount($category) - 1)->limit(1)->select('league_month_scores.*')->first();

        $query = $eligible()->with('learner')->select('league_month_scores.*');
        if ($boundary !== null) {
            // Everybody at least as good as the boundary tuple (`points >= P` is the range of the index).
            $query->where('league_month_scores.points', '>=', $boundary->points)->where(function ($q) use ($boundary): void {
                $q->where('league_month_scores.points', '>', $boundary->points)
                    ->orWhere('league_month_scores.long_words', '>', $boundary->long_words)
                    ->orWhere(fn ($q) => $q->where('league_month_scores.long_words', $boundary->long_words)->where('league_month_scores.active_days', '>=', $boundary->active_days));
            });
        }

        /** @var Collection<int, LeagueMonthScore> $scores */
        $scores = $query->get();

        $stamp = $month->freshTimestamp();
        $rows = [];
        $previous = null;
        $rank = 0;
        foreach ($scores as $i => $score) {
            $learner = $score->learner;
            if ($learner === null) {
                continue;
            }
            $ties = $previous !== null && $previous->points === $score->points && $previous->long_words === $score->long_words && $previous->active_days === $score->active_days;
            $rank = $ties ? $rank : $i + 1;
            $previous = $score;
            $rows[] = [
                'league_month_id' => $month->id,
                'learner_id' => $score->learner_id,
                'category' => $category->value,
                'rank' => $rank,
                'points' => $score->points,
                'active_days' => $score->active_days,
                'signals' => json_encode([...$this->signals($month, $score, $learner), 'long_words' => $score->long_words], JSON_THROW_ON_ERROR),
                'status' => LeagueMonthCandidateStatus::Candidate->value,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];
        }

        if ($rows !== []) {
            LeagueMonthCandidate::query()->insert($rows);
        }
    }

    /** @return list<MonthlyStanding> */
    private function computeTop(LeagueMonth $month): array
    {
        $rows = DB::table('league_month_scores as sc')
            ->leftJoin('learners as l', 'l.id', '=', 'sc.learner_id')
            ->where('sc.league_month_id', $month->id)
            ->where('sc.participant', true)
            ->orderByDesc('sc.points')
            ->orderByDesc('sc.active_days')
            ->orderBy('sc.learner_id')
            ->limit($this->topSize())
            ->get(['sc.learner_id', 'sc.points', 'sc.active_days', 'l.first_name', 'l.deleted_at']);

        $top = [];
        $rank = 0;
        foreach ($rows as $row) {
            $rank++;
            $top[] = new MonthlyStanding(
                learnerId: (int) $row->learner_id,
                name: $this->displayName($row->first_name ?? null, $row->deleted_at ?? null),
                points: (int) $row->points,
                activeDays: (int) $row->active_days,
                rank: $rank,
            );
        }

        return $top;
    }
}
