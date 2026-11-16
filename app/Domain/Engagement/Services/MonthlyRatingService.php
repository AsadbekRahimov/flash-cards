<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Administration\Services\PendingLeagueMonthsBadge;
use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Engagement\DTO\LastMonthView;
use App\Domain\Engagement\DTO\MonthlyMeView;
use App\Domain\Engagement\DTO\MonthlyMyLine;
use App\Domain\Engagement\DTO\MonthlyPrize;
use App\Domain\Engagement\DTO\MonthlyRatingOverview;
use App\Domain\Engagement\DTO\MonthlyStanding;
use App\Domain\Engagement\Enums\LeagueMonthCandidateStatus;
use App\Domain\Engagement\Enums\LeagueMonthStatus;
use App\Domain\Engagement\Support\LeaguePoints;
use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Learners\Support\StoredDate;
use App\Domain\Operations\Support\PureCache;
use App\Models\LeagueMonth;
use App\Models\LeagueMonthCandidate;
use App\Models\LeagueMonthScore;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection;
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
 */
final class MonthlyRatingService
{
    /** `v1` = arrays of {@see MonthlyStanding::toArray()}. */
    public const TOP_PREFIX = 'league:month:top:v1:';

    public function __construct(
        private readonly ProductEventRecorder $events,
        private readonly LeagueService $leagues,
    ) {}

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
            /** @var LeagueMonth $month */
            $month = DB::transaction(static fn (): LeagueMonth => LeagueMonth::query()->create([
                'starts_on' => $first->toDateString(),
                'ends_on' => $first->endOfMonth()->toDateString(),
                'status' => LeagueMonthStatus::Open->value,
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

            $this->upsertScores($locked, $now, null, $full ? null : $this->recentFrom($locked, $now));

            return true;
        });

        if ($written) {
            $this->stampRefresh($month, $now);
            PureCache::forgetMany([self::topKey($month->id)]);
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
     * The learner's right to the rating changed (blocked / unblocked, deleted / restored, opted out of / back into the
     * leagues): recomputes their row of the CURRENT open month at once and drops the cached top, so the list does not keep
     * a blocked leader until the month's close. After the commit of the caller's transaction (at once outside one); a failure
     * is reported and logged, never thrown — the action itself has succeeded. A learner with no row in the month: nothing to do.
     */
    public function participationChanged(Learner $learner, ?CarbonImmutable $now = null): void
    {
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
                    PureCache::forgetMany([self::topKey($month->id)]);
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

    /** What `GET /api/twa/league/month` shows. */
    public function overview(Learner $learner, CarbonImmutable $now): MonthlyRatingOverview
    {
        $first = $this->monthStart($now);
        $enabled = $this->enabled();
        $month = $enabled ? $this->currentMonth($now) : null;

        if ($month !== null) {
            $this->refreshLearner($month, $learner, $now);
        }

        return new MonthlyRatingOverview(
            enabled: $enabled,
            month: $month,
            startsOn: $first->toDateString(),
            endsOn: $first->endOfMonth()->toDateString(),
            closesAt: $first->addMonth()->utc()->toIso8601String(),
            daysLeft: $month === null ? 0 : $this->daysLeft($month, $now),
            participantsCount: $month === null ? 0 : $this->participantsCount($month),
            rules: $this->rules(),
            prizes: $month === null ? [] : $this->prizes($month),
            top: $month === null ? [] : $this->top($month),
            me: $month === null ? null : $this->myLine($month, $learner),
            optedOut: $this->leagues->isOptedOut($learner),
            lastMonth: $enabled ? $this->lastMonth($learner, $now) : null,
        );
    }

    /** What `GET /api/twa/league/month/me` shows (the finish screen): the viewer's fresh line, nothing of the others. */
    public function meView(Learner $learner, CarbonImmutable $now): MonthlyMeView
    {
        $first = $this->monthStart($now);
        $enabled = $this->enabled();
        $month = $enabled ? $this->currentMonth($now) : null;

        if ($month !== null) {
            $this->refreshLearner($month, $learner, $now);
        }

        return new MonthlyMeView(
            enabled: $enabled,
            startsOn: $first->toDateString(),
            endsOn: $first->endOfMonth()->toDateString(),
            daysLeft: $month === null ? 0 : $this->daysLeft($month, $now),
            me: $month === null ? null : $this->myLine($month, $learner),
        );
    }

    /**
     * The best `leagues.monthly.top` participants of the month, in the order of the rating (points, active days, older
     * account), with first names. Cached as arrays of scalars; the refresh and the close drop the key.
     *
     * @return list<MonthlyStanding>
     */
    public function top(LeagueMonth $month): array
    {
        $ttl = max(1, (int) config('leagues.monthly.top_cache_seconds', 120));

        /** @var list<MonthlyStandingArray> $rows */
        $rows = PureCache::store()->remember(
            self::topKey($month->id),
            $ttl,
            fn (): array => array_map(
                static fn (MonthlyStanding $row): array => $row->toArray(),
                $this->computeTop($month),
            ),
        );

        return array_map(MonthlyStanding::fromArray(...), $rows);
    }

    /** The learner's own line of the month, or null when they have no row (no activity in the month). */
    public function myLine(LeagueMonth $month, Learner $learner): ?MonthlyMyLine
    {
        $score = $this->scoreOf($month, $learner->id);
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
        );
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
     * @return list<MonthlyPrize>
     */
    public function prizes(LeagueMonth $month): array
    {
        $raw = $month->prizes;
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
            if ($place < 1 || $place > $this->places() || ($ru === '' && $uz === '')) {
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
                ->get(['c.place', 'c.points', 'l.first_name', 'l.deleted_at']);
            foreach ($rows as $row) {
                $winners[] = [
                    'place' => (int) $row->place,
                    'name' => $this->displayName($row->first_name ?? null, $row->deleted_at ?? null),
                    'points' => (int) $row->points,
                ];
            }
        }

        $score = $this->scoreOf($month, $learner->id);
        $me = $score !== null && $score->participant && $score->final_rank !== null
            ? ['rank' => $score->final_rank, 'points' => $score->points]
            : null;

        return new LastMonthView($month->starts_on->toDateString(), $month->ends_on->toDateString(), $month->status, $winners, $me);
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

        PureCache::forgetMany([self::topKey($month->id)]);
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

        return $closed;
    }

    public static function topKey(int $monthId): string
    {
        return self::TOP_PREFIX.$monthId;
    }

    public function dailyCap(): int
    {
        return max(0, (int) config('leagues.monthly.daily_cap', 300));
    }

    public function places(): int
    {
        return max(1, (int) config('leagues.monthly.places', 3));
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

    public function candidateCount(): int
    {
        return max($this->places(), (int) config('leagues.monthly.candidates', 10));
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
     * JSON path of the opt-out flag).
     *
     * @param  string|null  $recentFrom  `Y-m-d`
     */
    private function upsertScores(LeagueMonth $month, CarbonImmutable $now, ?int $onlyLearnerId, ?string $recentFrom): void
    {
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

        $bindings = [$month->id, OnboardingService::DONE, $stamp, $stamp];

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
            ."case when l.deleted_at is null and l.blocked_at is null and l.onboarding_state = {$typed('varchar')} and {$notOptedOut} then {$true} else {$false} end, "
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

    private function participantsCount(LeagueMonth $month): int
    {
        return LeagueMonthScore::query()->where('league_month_id', $month->id)->where('participant', true)->count();
    }

    /** `final_rank` of every participant by the order of the rating (a window function); non-participants get NULL. */
    private function writeFinalRanks(LeagueMonth $month): void
    {
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
