<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services\Scoring;

use App\Domain\Engagement\DTO\BoardKeys;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScoringMode;
use App\Domain\Learners\Support\Regions;
use App\Domain\Learners\Support\StoredDate;
use App\Domain\Operations\Support\PureCache;
use App\Models\LearnerScoreTotal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "All time" (docs/32 §5.2, S10): `learner_score_totals` - per learner and category - recomputed WHOLE from the source, never by deltas
 * and never in the answer (the core does not write it: an indexed `points` would make every credit a non-HOT update, docs/32 §12.1).
 *
 * The source (owner's decision В1: only the points v2 count; the old points are not carried over):
 *
 *   points       SUM(month_points) of the learner's rating days of a month v2 (`learner_score_days.month_version >= 2`), by the
 *                month's category (`month_category`): "all time" is the sum of the monthly counts - the limits and the day cap are in
 *                them already. Example (docs/30): 800 in a Free month + 600 in a Free month + 950 in a Pro month → Free 1 400, Pro 950,
 *                the personal total 2 350 (both rows, read by the screen - AllTimeRatingService);
 *   active_days  the days with month points; first_day / last_day - the first and the last rating day of the category;
 *   long_words   the lexemes with a long repetition credited to a month of the category: COUNT(long_<category>_on) of `learner_lexemes`
 *                (the marks the core and the correction keep; a lexeme counts once for all time) - read through the partial index
 *                `learner_lexemes_first_long_idx` (a `long_<category>_on` is never set without `first_long_on`: the core sets both, the
 *                correction recomputes both from the same journal);
 *   participant  as in the month: not deleted, not blocked, not opted out of the leagues;
 *   region_code  the learner's CURRENT region (docs/35 §2.4: "all time" is "people of the region").
 *
 * One `INSERT … SELECT … ORDER BY learner_id, category ON CONFLICT DO UPDATE` per set of learners - the same statement for:
 *
 *   one learner      {@see recomputeLearner()}: a change of the right to the rating, of the region, a correction of a day, the viewer's
 *                    own line when a rating day of theirs changed after their row (AllTimeRatingService);
 *   the recent ones  {@see refreshRecent()} with `leagues:refresh-month` (every 10 minutes): the learners whose rating day was written
 *                    since the start of the previous run (a marker on the default store) minus `refresh_margin_seconds` - a credit
 *                    stamped before that start and committed after it is still caught; no marker (the first run, a lost marker) - the
 *                    learners with a rating day in the last `refresh_recent_days`, as the month;
 *   everybody        {@see reconcileAll()} after the close of a month and with `--full`: by ranges of learner ids, a statement (and a
 *                    short transaction) each, only the rows that differ are written.
 *
 * Between the runs the table shows the totals of the last recompute (the others lag up to 10 minutes and the TTL of the top); the
 * viewer's own line is always fresh. The close of a month changes no sum (a sum does not depend on the month's status); a repeat gives
 * the same rows (idempotent).
 *
 * Concurrency: the recompute reads committed day rows without a lock and locks only the rows of `learner_score_totals` it writes - the
 * answer never touches them, so nothing waits for anything of the answer. Two recomputes lock the rows of a learner in the same order
 * (`ORDER BY learner_id, category`: no deadlock between a run and a hook), and the later write never loses to an older one: a row is
 * overwritten only by a recompute whose stamp is not older (`updated_at <= excluded.updated_at`; a recompute that loses this only lost
 * to a newer one).
 */
final class ScoreTotalsService
{
    /** The default (never evicting) store: the start of the last scheduled run, a marker - not a cache (docs/19 §3 п. 10). */
    public const MARKER = 'league:alltime:refreshed:v1';

    private const MARKER_TTL_SECONDS = 2 * 86400;

    /** What a rating day without `updated_at` reads as: later than any write, so the learner is recomputed. */
    public const NEVER_STAMPED = '9999-12-31 00:00:00';

    private const MODE_LEARNER = 'learner';

    private const MODE_SET = 'set';

    private const MODE_RANGE = 'range';

    public function __construct(private readonly ScoreRules $rules) {}

    /** Whether "all time" is recomputed: the leagues on and the word points recorded (`shadow` writes no v2 day - nothing to sum). */
    public function enabled(): bool
    {
        return (bool) config('leagues.enabled', true) && $this->rules->mode() !== ScoringMode::Off;
    }

    /** Whether "all time" is shown (`live` only: in `shadow` nothing of v2 is shown). */
    public function visible(): bool
    {
        return (bool) config('leagues.enabled', true) && $this->rules->mode() === ScoringMode::Live;
    }

    /** Recomputes the learner's rows (both categories) from the source. One statement; nothing when "all time" is off. */
    public function recomputeLearner(int $learnerId, CarbonImmutable $now): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->upsert(self::MODE_LEARNER, [$learnerId], $now, onlyChanged: false);
    }

    /**
     * {@see recomputeLearner()} after the commit of the caller's transaction (at once outside one), with the cached tables dropped.
     * A failure is reported, never thrown: the action that changed the learner has succeeded.
     */
    public function learnerChanged(int $learnerId, ?CarbonImmutable $now = null): void
    {
        DB::afterCommit(function () use ($learnerId, $now): void {
            try {
                if (! $this->enabled()) {
                    return;
                }
                $this->recomputeLearner($learnerId, $now ?? CarbonImmutable::now());
                $this->forgetTables();
            } catch (Throwable $e) {
                report($e);
                Log::warning('league.all_time_learner_refresh_failed', ['learner_id' => $learnerId, 'exception' => $e::class, 'error' => $e->getMessage()]);
            }
        });
    }

    /**
     * The scheduled run: the learners whose rating day was written since the previous run (see the class comment). Returns the number
     * of rows written.
     */
    public function refreshRecent(CarbonImmutable $now): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $since = $this->marker();
        if ($since !== null) {
            $since = $since->subSeconds(max(0, (int) config('leagues.all_time.refresh_margin_seconds', 300)));
            if ($since->isAfter($now)) {
                $since = $now;
            }
            $fromDay = $this->rules->ratingDay($since)->toDateString();
        } else {
            $days = max(1, (int) config('leagues.monthly.refresh_recent_days', 3));
            $fromDay = $this->rules->ratingDay($now)->subDays($days - 1)->toDateString();
        }

        // The learners: one index range of `learner_score_days (rating_day)`, the stamp filtered on it.
        $bindings = [StoredDate::of($fromDay)];
        $set = 'select r.learner_id from learner_score_days r where r.rating_day >= ? and r.month_version >= 2';
        if ($since !== null) {
            $set .= ' and (r.updated_at is null or r.updated_at >= ?)';
            $bindings[] = (string) (new LearnerScoreTotal)->fromDateTime($since);
        }

        $written = $this->upsert(self::MODE_SET, [$set, $bindings], $now, onlyChanged: false);

        Cache::put(self::MARKER, $now->utc()->toIso8601String(), self::MARKER_TTL_SECONDS);
        $this->forgetTables();

        return $written;
    }

    /**
     * Everybody, by ranges of `leagues.all_time.reconcile_batch` learner ids (a statement each, its own short transaction): after the
     * close of a month (the reconcile docs/32 §7 asks for), and `leagues:refresh-month --full`. Only the rows that differ are written.
     * Returns the number of rows written.
     */
    public function reconcileAll(CarbonImmutable $now): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $batch = max(100, (int) config('leagues.all_time.reconcile_batch', 5000));
        $max = (int) DB::table('learner_score_days')->max('learner_id');
        $written = 0;
        for ($from = 0; $from <= $max; $from += $batch) {
            $written += $this->upsert(self::MODE_RANGE, [$from, $from + $batch], $now, onlyChanged: true);
        }

        $this->forgetTables();

        return $written;
    }

    /** Drops the cached tops and numbers of participants of every table of "all time" (both categories, the whole and every region). */
    public function forgetTables(): void
    {
        PureCache::forgetMany(self::tableKeys());
    }

    /**
     * The keys {@see forgetTables()} drops: top and count of every category, whole and per region.
     *
     * @return list<string>
     */
    public static function tableKeys(): array
    {
        $keys = [];
        foreach (ScoreCategory::cases() as $category) {
            array_push($keys, ...BoardKeys::allTime($category)->written());
            foreach (Regions::codes() as $region) {
                array_push($keys, ...BoardKeys::allTime($category, $region)->written());
            }
        }

        return $keys;
    }

    /** The start of the last scheduled run, or null (none, or the marker is gone). */
    private function marker(): ?CarbonImmutable
    {
        $raw = Cache::get(self::MARKER);
        if (! is_string($raw)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The one statement (see the class comment). `$scope` by `$mode`: `[learnerId]`, `[setSql, setBindings]` (a subquery of learner
     * ids), `[fromId, toId)`. Bindings only; the driver-specific pieces are constants.
     *
     * @param  array<int, mixed>  $scope
     */
    private function upsert(string $mode, array $scope, CarbonImmutable $now, bool $onlyChanged): int
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        [$true, $false] = $pgsql ? ['true', 'false'] : ['1', '0'];
        $typed = static fn (string $type): string => $pgsql ? "?::{$type}" : '?';
        $notOptedOut = $pgsql
            ? "coalesce(l.meta->>'leagues_opt_out', 'false') <> 'true'"
            : "coalesce(json_extract(l.meta, '$.leagues_opt_out'), 0) <> 1";

        $where = static function (string $alias) use ($mode, $scope): array {
            return match ($mode) {
                self::MODE_LEARNER => [" and {$alias}.learner_id = ?", [$scope[0]]],
                self::MODE_SET => [" and {$alias}.learner_id in ({$scope[0]})", $scope[1]],
                default => [" and {$alias}.learner_id >= ? and {$alias}.learner_id < ?", [$scope[0], $scope[1]]],
            };
        };
        [$daysScope, $daysBindings] = $where('s');
        [$longScope, $longBindings] = $where('x');

        $stamp = (string) (new LearnerScoreTotal)->fromDateTime($now);

        $columns = ['region_code', 'points', 'long_words', 'active_days', 'first_day', 'last_day', 'participant'];
        $set = implode(', ', array_map(static fn (string $c): string => "{$c} = excluded.{$c}", $columns)).', updated_at = excluded.updated_at';
        // Never overwrite a newer recompute (two at once: a run and a hook) - see the class comment.
        $guard = '(learner_score_totals.updated_at is null or learner_score_totals.updated_at <= excluded.updated_at)';
        if ($onlyChanged) {
            $distinct = $pgsql ? 'is distinct from' : 'is not';
            $guard .= ' and ('.implode(' or ', array_map(static fn (string $c): string => "learner_score_totals.{$c} {$distinct} excluded.{$c}", $columns)).')';
        }

        return DB::affectingStatement(
            'insert into learner_score_totals (learner_id, category, region_code, points, long_words, active_days, first_day, last_day, participant, updated_at) '
            .'select a.learner_id, a.category, l.region_code, a.points, '
            ."case when a.category = 'pro' then coalesce(x.pro_long, 0) else coalesce(x.free_long, 0) end, "
            .'a.active_days, a.first_day, a.last_day, '
            ."case when l.deleted_at is null and l.blocked_at is null and {$notOptedOut} then {$true} else {$false} end, "
            ."{$typed('timestamp')} "
            .'from ('
            .'select s.learner_id, s.month_category as category, sum(s.month_points) as points, '
            .'sum(case when s.month_points > 0 then 1 else 0 end) as active_days, min(s.rating_day) as first_day, max(s.rating_day) as last_day '
            ."from learner_score_days s where s.month_version >= 2{$daysScope} group by s.learner_id, s.month_category"
            .') a '
            .'join learners l on l.id = a.learner_id '
            .'left join ('
            .'select x.learner_id, count(x.long_free_on) as free_long, count(x.long_pro_on) as pro_long '
            ."from learner_lexemes x where x.first_long_on is not null{$longScope} group by x.learner_id"
            .') x on x.learner_id = a.learner_id '
            // sqlite: an INSERT … SELECT with joins needs a WHERE before ON CONFLICT (the parser would read it as a join's ON).
            .'where a.learner_id is not null '
            // The lock order of every recompute: by learner, then category (free before pro).
            .'order by a.learner_id, a.category '
            ."on conflict (learner_id, category) do update set {$set} where {$guard}",
            [$stamp, ...$daysBindings, ...$longBindings],
        );
    }
}
