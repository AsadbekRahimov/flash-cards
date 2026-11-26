<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services\Scoring;

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Engagement\DTO\AllTimeLine;
use App\Domain\Engagement\DTO\AllTimeOverview;
use App\Domain\Engagement\DTO\BoardCursor;
use App\Domain\Engagement\DTO\BoardKeys;
use App\Domain\Engagement\DTO\BoardPageCursor;
use App\Domain\Engagement\DTO\BoardScope;
use App\Domain\Engagement\DTO\MonthlyBoardPage;
use App\Domain\Engagement\DTO\MonthlyMyLine;
use App\Domain\Engagement\DTO\RegionChoice;
use App\Domain\Engagement\DTO\RegionStanding;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScorePeriod;
use App\Domain\Engagement\Exceptions\InvalidBoardCursor;
use App\Domain\Engagement\Exceptions\RegionNotSet;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Learners\Support\StoredDate;
use App\Models\Learner;
use App\Models\LearnerScoreTotal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The reading of "all time" (docs/32 §5, §8.3, S10) and of its tables of a region (docs/35 §2.4, R6-R7) - `GET /league/all-time`.
 *
 *   the table    `learner_score_totals` of one category (`BoardScope::allTime`), the whole of it or a region; competition places,
 *                keyset pages, neighbours and the gap - {@see BoardReader} over the one {@see ScoreBoard}; the top, the number of
 *                participants, the places and the neighbours cached ({@see BoardKeys::allTime()}), the tops dropped by every recompute;
 *   the viewer   their rows (both categories) read with the moment their rating days of today and yesterday last changed - ONE statement;
 *                a row older than such a day is recomputed first (one statement, {@see ScoreTotalsService::recomputeLearner()}): the
 *                viewer's own line is always fresh, the others lag behind the scheduled recompute (10 minutes) and the TTL of the top.
 *                Only today's (and around midnight yesterday's) rating day takes credits; an older day changes only by a correction, and
 *                that recomputes the learner itself;
 *   the region   a table of a region is shown from `leagues.regions.min_participants` (5) participants; below that it has no rows and
 *                says so. Any region may be opened (owner's decision docs/31 §5г) - read-only when it is not the viewer's.
 *
 * Shown in `live` only ({@see ScoreTotalsService::visible()}); otherwise the answer is "not available".
 */
final class AllTimeRatingService
{
    public function __construct(
        private readonly ScoreTotalsService $totals,
        private readonly BoardReader $reader,
        private readonly ScoreRules $rules,
        private readonly EntitlementResolver $entitlements,
        private readonly LeagueService $leagues,
    ) {}

    public function enabled(): bool
    {
        return $this->totals->visible();
    }

    /**
     * @throws RegionNotSet `scope=region` without a code from a learner who has no region
     */
    public function overview(Learner $learner, CarbonImmutable $now, ?ScoreCategory $requested, RegionChoice $choice): AllTimeOverview
    {
        if (! $this->enabled()) {
            return new AllTimeOverview(enabled: false);
        }

        ['rows' => $rows, 'month_category' => $monthCategory] = $this->freshRows($learner, $now);
        $myCategory = $monthCategory ?? ScoreCategory::fromPlan($this->entitlements->for($learner, $now)->plan);
        $category = $requested ?? $myCategory;
        $region = $this->region($learner, $choice);

        $scope = BoardScope::allTime($category, $region);
        $keys = BoardKeys::allTime($category, $region);
        $participants = $this->reader->count($scope, $keys, $this->topTtl());
        $regionView = $region === null ? null : $this->regionStanding($region, $participants);
        $hidden = $regionView !== null && ! $regionView->shown;

        $mine = $rows[$category->value] ?? null;
        $inTable = $mine !== null && $mine->participant && ($region === null || $mine->region_code === $region);

        $me = $mine === null ? null : $this->line($learner, $mine);
        $neighbours = null;
        if ($inTable && ! $hidden && $me !== null) {
            $rank = $region === null ? $me->rank : $me->region?->rank;
            $neighbours = $this->reader->neighbours($scope, $keys, $this->rankTtl(), $this->cursorOf($mine), $rank);
            $me = new AllTimeLine(
                $me->category, $me->rank, $me->points, $me->longWords, $me->activeDays, $me->participant, $me->notCountedReason,
                $me->firstDay, $me->lastDay, $me->region, $neighbours['next_gap'],
            );
        }

        $top = $hidden ? [] : $this->reader->top($scope, $keys, $this->topSize(), $this->topTtl());
        $last = $top === [] ? null : $top[count($top) - 1];

        $byCategory = [];
        foreach ($rows as $value => $row) {
            $byCategory[$value] = $row->points;
        }

        return new AllTimeOverview(
            enabled: true,
            countedSince: $this->rules->version(2)?->startsOn,
            category: $category,
            myCategory: $myCategory,
            choice: $choice,
            region: $regionView,
            participants: $participants,
            top: $top,
            me: $me,
            neighbours: $neighbours,
            // The top is the first page; more participants than it shows - the list goes on after its last row.
            nextCursor: $last !== null && $participants > count($top) ? $last->cursor() : null,
            readOnly: ! $inTable,
            myTotal: array_sum($byCategory),
            byCategory: $byCategory,
            optedOut: $this->leagues->isOptedOut($learner),
        );
    }

    /**
     * A page of a table of "all time" after `?after=`: the cursor must be one of exactly this table (category, region), else 422.
     *
     * @throws InvalidBoardCursor the cursor is not one of this table, or "all time" is not shown
     * @throws RegionNotSet `scope=region` without a code from a learner who has no region
     */
    public function page(Learner $learner, CarbonImmutable $now, ?ScoreCategory $requested, RegionChoice $choice, string $after, int $limit = 50): MonthlyBoardPage
    {
        if (! $this->enabled()) {
            throw new InvalidBoardCursor('"All time" has no pages now.');
        }

        $category = $requested ?? $this->ownCategory($learner, $now);
        $region = $this->region($learner, $choice);
        $cursor = BoardPageCursor::decodeAllTime($after, $category, $region);
        if ($cursor === null) {
            throw new InvalidBoardCursor('The cursor is not one of this table.');
        }

        $scope = BoardScope::allTime($category, $region);
        $regionView = null;
        if ($region !== null) {
            $regionView = $this->regionStanding($region, $this->reader->count($scope, BoardKeys::allTime($category, $region), $this->topTtl()));
        }

        /** @var LearnerScoreTotal|null $mine */
        $mine = LearnerScoreTotal::query()->where('learner_id', $learner->id)->where('category', $category->value)->first();
        $inTable = $mine !== null && $mine->participant && ($region === null || $mine->region_code === $region);

        $page = $regionView !== null && ! $regionView->shown ? ['rows' => [], 'next' => null] : $this->reader->page($scope, $cursor, $limit);

        return new MonthlyBoardPage(0, $category, ! $inTable, $page['rows'], $page['next'], $regionView, allTime: true);
    }

    /**
     * The viewer's rows by category, recomputed first when one of their rating days of today or yesterday changed after them, and the
     * category of their current month (null before its first credit).
     *
     * @return array{rows: array<string, LearnerScoreTotal>, month_category: ScoreCategory|null}
     */
    private function freshRows(Learner $learner, CarbonImmutable $now): array
    {
        $today = $this->rules->ratingDay($now);

        // The last change of the rating days that can still take credits (today, yesterday around midnight); NULL = none.
        $changed = DB::table('learner_score_days as d')
            ->where('d.learner_id', $learner->id)
            ->where('d.rating_day', '>=', StoredDate::of($today->subDay()->toDateString()))
            ->where('d.month_version', '>=', 2)
            ->selectRaw('max(coalesce(d.updated_at, ?))', [ScoreTotalsService::NEVER_STAMPED]);
        $monthCategory = DB::table('learner_score_periods as p')
            ->where('p.learner_id', $learner->id)
            ->where('p.period', ScorePeriod::Month->value)
            ->where('p.starts_on', StoredDate::of($this->rules->periodStart(ScorePeriod::Month, $today)->toDateString()))
            ->select('p.category');

        // From the learner's own row (it exists: the request is theirs), so there is a result with or without totals.
        $result = DB::table('learners as l')
            ->leftJoin('learner_score_totals as t', 't.learner_id', '=', 'l.id')
            ->where('l.id', $learner->id)
            ->select('t.*')
            ->selectSub($changed, 'rating_days_changed_at')
            ->selectSub($monthCategory, 'current_month_category')
            ->get();

        $rows = [];
        $changedAt = null;
        $category = null;
        $writtenAt = null;
        foreach ($result as $record) {
            $attributes = get_object_vars($record);
            $changedAt = $attributes['rating_days_changed_at'] ?? null;
            $category = $attributes['current_month_category'] ?? null;
            unset($attributes['rating_days_changed_at'], $attributes['current_month_category']);
            if (($attributes['learner_id'] ?? null) === null) {
                continue;
            }
            /** @var LearnerScoreTotal $row */
            $row = (new LearnerScoreTotal)->newFromBuilder($attributes);
            $rows[$row->category->value] = $row;
            $stamp = $attributes['updated_at'] ?? null;
            if (is_string($stamp) && ($writtenAt === null || $stamp > $writtenAt)) {
                $writtenAt = $stamp;
            }
        }

        $monthCategory = is_string($category) ? ScoreCategory::tryFrom($category) : null;

        // Strictly newer: a credit stamped in the very second of the recompute may not be in it (docs/38 §3.2, as the month).
        $fresh = $changedAt === null
            || ($rows !== [] && is_string($changedAt) && is_string($writtenAt)
                && CarbonImmutable::parse($writtenAt, 'UTC')->greaterThan(CarbonImmutable::parse($changedAt, 'UTC')));
        if ($fresh) {
            return ['rows' => $rows, 'month_category' => $monthCategory];
        }

        $this->totals->recomputeLearner($learner->id, $now);

        $rows = [];
        foreach (LearnerScoreTotal::query()->where('learner_id', $learner->id)->get() as $row) {
            $rows[$row->category->value] = $row;
        }

        return ['rows' => $rows, 'month_category' => $monthCategory];
    }

    /** The category of the viewer's current month, else of their plan (a page asked without `?category=`). */
    private function ownCategory(Learner $learner, CarbonImmutable $now): ScoreCategory
    {
        $value = DB::table('learner_score_periods')
            ->where('learner_id', $learner->id)
            ->where('period', ScorePeriod::Month->value)
            ->where('starts_on', StoredDate::of($this->rules->periodStart(ScorePeriod::Month, $this->rules->ratingDay($now))->toDateString()))
            ->value('category');

        return (is_string($value) ? ScoreCategory::tryFrom($value) : null) ?? ScoreCategory::fromPlan($this->entitlements->for($learner, $now)->plan);
    }

    /** @throws RegionNotSet */
    private function region(Learner $learner, RegionChoice $choice): ?string
    {
        if (! $choice->region) {
            return null;
        }

        // "All time" is "people of the region": the viewer's own is the current one (docs/35 §2.4).
        $region = $choice->code ?? $learner->region_code;
        if ($region === null) {
            throw new RegionNotSet('Choose your region first.');
        }

        return $region;
    }

    private function line(Learner $learner, LearnerScoreTotal $row): AllTimeLine
    {
        $category = $row->category;
        $rank = $row->participant
            ? $this->reader->rank(BoardScope::allTime($category), BoardKeys::allTime($category), $this->rankTtl(), $row->points, $row->long_words, $row->active_days)
            : null;

        $region = null;
        if ($row->region_code !== null) {
            $scope = BoardScope::allTime($category, $row->region_code);
            $keys = BoardKeys::allTime($category, $row->region_code);
            $standing = $this->regionStanding($row->region_code, $this->reader->count($scope, $keys, $this->topTtl()));
            $regionRank = $row->participant && $standing->shown
                ? $this->reader->rank($scope, $keys, $this->rankTtl(), $row->points, $row->long_words, $row->active_days)
                : null;
            $region = new RegionStanding($standing->code, $standing->participants, $standing->shown, $standing->minParticipants, $regionRank);
        }

        $reason = null;
        if (! $row->participant) {
            $reason = match (true) {
                $learner->blocked_at !== null => MonthlyMyLine::REASON_BLOCKED,
                $this->leagues->isOptedOut($learner) => MonthlyMyLine::REASON_OPTED_OUT,
                default => null,
            };
        }

        return new AllTimeLine(
            category: $category,
            rank: $rank,
            points: $row->points,
            longWords: $row->long_words,
            activeDays: $row->active_days,
            participant: $row->participant,
            notCountedReason: $reason,
            firstDay: $row->first_day->toDateString(),
            lastDay: $row->last_day->toDateString(),
            region: $region,
            nextGap: null,
        );
    }

    private function regionStanding(string $region, int $participants): RegionStanding
    {
        $min = self::minParticipants();

        return new RegionStanding($region, $participants, $participants >= $min, $min);
    }

    private function cursorOf(LearnerScoreTotal $row): BoardCursor
    {
        return new BoardCursor($row->points, $row->long_words, $row->active_days, $row->learner_id);
    }

    /** The threshold of a table of a region (docs/35 §2.4): shown from this many participants. */
    public static function minParticipants(): int
    {
        return max(1, (int) config('leagues.regions.min_participants', 5));
    }

    private function topSize(): int
    {
        return max(1, (int) config('leagues.monthly.top', 50));
    }

    private function topTtl(): int
    {
        return max(1, (int) config('leagues.all_time.top_cache_seconds', 120));
    }

    private function rankTtl(): int
    {
        return max(1, (int) config('leagues.all_time.rank_cache_seconds', 60));
    }
}
