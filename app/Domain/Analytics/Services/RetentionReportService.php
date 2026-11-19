<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Analytics\Support\RetentionSql;
use App\Domain\Analytics\Support\RetentionWeek;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learners\Support\StoredDate;
use App\Models\Learner;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Retention report of the platform for the admin page «Удержание» (the numbers that tell whether a change of the training
 * keeps people): weekly registration cohorts with D1 / D3 / D7 / D14 / D30, the first training session, and D1 / D7 by
 * interface language and by "had a trial". Read-only, platform-wide: the page is the access check (active admin).
 *
 * Definitions (the page and docs/ADMIN_GUIDE.md repeat them):
 *  - cohort: learners (not deleted) whose `created_at` falls in a Monday-Sunday week of {@see REPORT_TIMEZONE}; the last
 *    {@see WEEKS} weeks, the current one included (partial);
 *  - "studied on a day": a `learner_daily_stats` row with `reviews_count > 0`; the row's `date` is the learner's LOCAL date;
 *  - D-N: share of the cohort that studied exactly N local days after the local date of registration (registration moment in
 *    the learner's own zone, the way {@see LearnerClock} reads a day); "within 7 days": any of the days 1-7;
 *  - a cell is shown only when day N has ended for EVERY learner of the cohort in their own zone, otherwise it is null
 *    (a dash, never 0 %);
 *  - activation: share of the cohort with a first accepted learning answer (any `training_reviews` row) earlier than
 *    {@see ACTIVATION_HOURS} hours after `learners.created_at` (23 h 59 counts, 24 h 00 does not); cut by `interface_locale`
 *    (ru / uz) and by solo / class, "class" = a `group_members` row with `joined_at` earlier than 24 hours after registration in
 *    a group that is linked to a center now (`telegram_groups.organization_id` not null; a later leave does not undo it). The
 *    cell is null until 24 hours have passed since the registration of the cohort's youngest learner;
 *  - played the game on day N ({@see GAME_DAYS}): a `learner_daily_stats` row of that local day with `game_runs_count > 0`
 *    (completed and failed runs), shown beside learning D1 / D7 and never added into them; the same maturity rule. The practice
 *    has no per-day column and is not shown;
 *  - first session: the learner's first personal `training_sessions` row; "finished" = `done_reason = finished`, "abandoned" =
 *    closed any other way or still open past `expires_at`; cards = `training_reviews` rows of that session; time = the sum of
 *    their `time_spent_ms` (the active time, not `ended_at - started_at`, which for an expired session is the moment the
 *    scheduler closed it); "second session in 24 hours / 7 days" is the first session that began at least
 *    {@see ROUND_GAP_MINUTES} minutes after the START of the first one and no later than 24 hours / 7 days after it (a round played
 *    at once is not "coming back"), among learners whose first session began at least that long ago; "one more round at once" is
 *    a session that began sooner than {@see ROUND_GAP_MINUTES} minutes after the first one, among learners whose first session
 *    began at least that long ago.
 *
 * Queries: a constant number (zones, cohorts with the activation, retention with the game days, first-session totals,
 * first-session histograms), each one
 * aggregate over the learners of the window - no learner is loaded into memory, so the count does not depend on their number;
 * medians come from a histogram (value => learners), a few hundred rows at most. The result is an array of scalars in the
 * pure cache ({@see WidgetCache}, docs/19 §3); on read every number is cast back ({@see coerce()}) because Redis hands a
 * top-level number over as a string.
 */
final class RetentionReportService
{
    /** Weeks run Monday-Sunday in this zone (the product's home calendar), whatever zone a learner has. */
    public const REPORT_TIMEZONE = 'Asia/Tashkent';

    public const WEEKS = 12;

    /** The weeks of the language / trial slice. */
    public const SEGMENT_WEEKS = 4;

    /** The days of the table, in the order of its columns. */
    public const DAYS = [1, 3, 7, 14, 30];

    /** "Came back within N days": any day 1..N. */
    public const WITHIN_DAYS = 7;

    /** The days the game row is shown for, beside the learning D1 / D7 (never added into them). */
    public const GAME_DAYS = [1, 7];

    /** Activation: the first accepted learning answer within this many hours of registration. */
    public const ACTIVATION_HOURS = 24;

    /** The cuts of the activation, in the order of the columns: everybody, interface language, solo / class. */
    public const ACTIVATION_CUTS = ['all', 'ru', 'uz', 'solo', 'class'];

    /** The first-session block looks at learners registered in this many last days. */
    public const FIRST_SESSION_DAYS = 30;

    /**
     * A session that starts sooner than this after the first one began is "one more round" played at once (the Mini App opens it
     * a minute after the previous one, docs/06 §13); only a later one means the learner came back.
     */
    public const ROUND_GAP_MINUTES = 60;

    public const CACHE_WIDGET = 'retention';

    /** Seconds the figures are fresh (docs/19 §3: minutes, the figures do not need to be live). */
    public const CACHE_TTL = 600;

    public function __construct(
        private readonly WidgetCache $cache,
        private readonly LearnerClock $clock,
    ) {}

    /**
     * The report from the cache; computed once per {@see CACHE_TTL}, a stale one is still served while the next reader refreshes it.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $compute = fn (): array => $this->compute(CarbonImmutable::now());

        $cached = $this->cache->remember(self::CACHE_WIDGET, 'all', self::CACHE_TTL, $compute);
        $report = self::coerce($cached);

        if (! self::isReport($report)) {
            // Whatever sits under the key is not a report of this version: drop it and compute again.
            $this->forget();
            $report = self::coerce($this->cache->remember(self::CACHE_WIDGET, 'all', self::CACHE_TTL, $compute));
        }

        return $report;
    }

    /** The «Обновить» button: the next {@see report()} reads the database. */
    public function forget(): void
    {
        $this->cache->forget(self::CACHE_WIDGET, 'all');
    }

    /**
     * The figures as of `$now`, from the database (no cache).
     *
     * @return array<string, mixed>
     */
    public function compute(CarbonImmutable $now): array
    {
        $sql = RetentionSql::for(DB::connection());
        $weeks = $this->weeks($now);

        $blocks = $this->cohortBlocks($sql, $weeks, $now);

        return [
            'generated_at' => $now->toIso8601String(),
            'cohorts' => $blocks['cohorts'],
            'segments' => $blocks['segments'],
            'first_session' => $this->firstSession($sql, $now),
        ];
    }

    /**
     * Cached numbers back to numbers: a top-level number leaves Redis as a string, and a string under `declare(strict_types=1)`
     * is a 500. Numeric strings become int / float, every other value is kept.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public static function coerce(array $value): array
    {
        $out = [];

        foreach ($value as $key => $item) {
            $out[$key] = match (true) {
                is_array($item) => self::coerce($item),
                is_string($item) && is_numeric($item) => ctype_digit($item) ? (int) $item : (float) $item,
                default => $item,
            };
        }

        return $out;
    }

    /** @param  array<array-key, mixed>  $report */
    private static function isReport(array $report): bool
    {
        return isset($report['generated_at'], $report['cohorts'], $report['segments'], $report['first_session'])
            && is_string($report['generated_at']) && is_array($report['cohorts']) && is_array($report['segments'])
            && is_array($report['first_session']) && array_key_exists('round_again', $report['first_session']);
    }

    // -------------------------------------------------------------------------------------------------------------------
    // Cohorts and the language / trial slice
    // -------------------------------------------------------------------------------------------------------------------

    /**
     * The Monday-Sunday weeks, newest first: `[start (local midnight), end (the next Monday, exclusive)]`.
     *
     * @return list<array{CarbonImmutable, CarbonImmutable}>
     */
    private function weeks(CarbonImmutable $now): array
    {
        $monday = $now->setTimezone(new DateTimeZone(self::REPORT_TIMEZONE))->startOfWeek(CarbonInterface::MONDAY);
        $weeks = [];

        for ($i = 0; $i < self::WEEKS; $i++) {
            $start = $monday->subWeeks($i)->startOfDay();
            $weeks[] = [$start, $start->addWeek()->startOfDay()];
        }

        return $weeks;
    }

    /**
     * @param  list<array{CarbonImmutable, CarbonImmutable}>  $weeks
     * @return array{cohorts: list<array<string, mixed>>, segments: array<string, mixed>}
     */
    private function cohortBlocks(RetentionSql $sql, array $weeks, CarbonImmutable $now): array
    {
        $from = $weeks[self::WEEKS - 1][0];
        $to = $weeks[0][1];

        $names = array_values(array_map(
            static fn (mixed $name): string => (string) $name,
            DB::table('learners')
                ->whereNull('deleted_at')
                ->where('created_at', '>=', self::db($from))
                ->where('created_at', '<', self::db($to))
                ->distinct()
                ->pluck('timezone')
                ->all(),
        ));

        [$cte, $cteBindings] = $this->cohortCte($sql, $weeks, $names, $now, $from, $to);

        $cohortRows = array_values(DB::select(
            "WITH c AS ({$cte}) SELECT wk, locale, trial, cls, COUNT(*) AS size, SUM(onboarded) AS onboarded, SUM(started) AS started, "
            .'SUM(act) AS activated, SUM(young) AS young, '
            .'MIN('.$sql->daysBetween('today', 'reg').') AS elapsed FROM c GROUP BY wk, locale, trial, cls',
            $cteBindings,
        ));

        $retentionRows = $names === [] ? [] : $this->retentionRows($sql, $cte, $cteBindings, $from);

        return $this->assemble($weeks, $cohortRows, $retentionRows);
    }

    /**
     * The learners of the window with their week, local registration date, local "today", language, trial flag, first-answer
     * flag and the activation flags (answered in 24 hours, joined a center's group in 24 hours, registered less than 24 hours
     * ago); one row per learner, bindings in the order of the placeholders. The `EXISTS` subqueries run on the indexes
     * `training_reviews (learner_id, created_at)` and `group_members (learner_id, status)`.
     *
     * @param  list<array{CarbonImmutable, CarbonImmutable}>  $weeks
     * @param  list<string>  $names  the zone names that occur
     * @return array{string, list<int|string>}
     */
    private function cohortCte(RetentionSql $sql, array $weeks, array $names, CarbonImmutable $now, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $created = [];
        $current = [];

        foreach ($names as $name) {
            $zone = $this->clock->timezone((new Learner)->forceFill(['timezone' => $name]));
            $transitions = $zone->getTransitions($from->getTimestamp() - 86400, $to->getTimestamp() + 86400);

            $created[$name] = array_map(
                static fn (array $t): array => [(int) $t['ts'], (int) $t['offset']],
                $transitions === [] ? [['ts' => 0, 'offset' => 0]] : $transitions,
            );
            $current[$name] = [[0, $zone->getOffset(new DateTimeImmutable('@'.$now->getTimestamp()))]];
        }

        $week = 'CASE';
        $weekBindings = [];
        foreach ($weeks as $index => [$start]) {
            $week .= " WHEN l.created_at >= {$sql->ts()} THEN {$sql->int()}";
            $weekBindings[] = self::db($start);
            $weekBindings[] = $index;
        }
        $week .= ' END';

        [$createdOffset, $createdBindings] = $sql->offsetCase('l.timezone', 'l.created_at', $created);
        [$nowOffset, $nowBindings] = $sql->offsetCase('l.timezone', null, $current);

        $registered = $sql->dateOf($sql->shift('l.created_at', $createdOffset));
        $today = $sql->dateOf($sql->shift($sql->ts(), $nowOffset));
        $deadline = $sql->shift('l.created_at', $sql->int());
        $window = self::ACTIVATION_HOURS * 3600;

        $query = "SELECT l.id AS id, l.interface_locale AS locale, {$week} AS wk, {$registered} AS reg, {$today} AS today, "
            .'CASE WHEN l.onboarding_completed_at IS NULL THEN 0 ELSE 1 END AS onboarded, '
            ."CASE WHEN EXISTS (SELECT 1 FROM subscriptions su WHERE su.subscriber_type = 'learner' AND su.subscriber_id = l.id AND su.provider = ?) THEN 1 ELSE 0 END AS trial, "
            .'CASE WHEN EXISTS (SELECT 1 FROM training_reviews r WHERE r.learner_id = l.id) THEN 1 ELSE 0 END AS started, '
            .'CASE WHEN EXISTS (SELECT 1 FROM training_reviews ra WHERE ra.learner_id = l.id AND ra.created_at < '.$deadline.') THEN 1 ELSE 0 END AS act, '
            .'CASE WHEN EXISTS (SELECT 1 FROM group_members gm JOIN telegram_groups tg ON tg.id = gm.telegram_group_id '
            .'WHERE gm.learner_id = l.id AND tg.organization_id IS NOT NULL AND gm.joined_at < '.$deadline.') THEN 1 ELSE 0 END AS cls, '
            ."CASE WHEN l.created_at > {$sql->ts()} THEN 1 ELSE 0 END AS young "
            ."FROM learners l WHERE l.deleted_at IS NULL AND l.created_at >= {$sql->ts()} AND l.created_at < {$sql->ts()}";

        return [$query, [
            ...$weekBindings,
            ...$createdBindings,
            self::db($now),
            ...$nowBindings,
            SubscriptionProvider::Trial->value,
            $window,
            $window,
            self::db($now->subSeconds($window)),
            self::db($from),
            self::db($to),
        ]];
    }

    /**
     * Per (week, language, trial): how many learners studied exactly on day N after registration, and on any of the days
     * 1..WITHIN_DAYS. `learner_daily_stats (learner_id, date)` is unique, so a row is one learner on one day.
     *
     * @param  list<int|string>  $cteBindings
     * @return list<stdClass>
     */
    private function retentionRows(RetentionSql $sql, string $cte, array $cteBindings, CarbonImmutable $from): array
    {
        $select = '';
        $bindings = [];
        foreach (self::DAYS as $day) {
            $select .= ' SUM(CASE WHEN x.d = ? THEN 1 ELSE 0 END) AS d'.$day.',';
            $bindings[] = $day;
        }
        $select .= ' COUNT(DISTINCT CASE WHEN x.d BETWEEN ? AND ? THEN x.id END) AS within';
        array_push($bindings, 1, self::WITHIN_DAYS);

        // A learner's local date is at most a day before the UTC date of the window start: the lower bound only narrows the scan.
        $lowerBound = StoredDate::of($from->subDay()->toDateString());

        return array_values(DB::select(
            "WITH c AS ({$cte}) SELECT x.wk, x.locale, x.trial,{$select} FROM ("
            .'SELECT c.id AS id, c.wk AS wk, c.locale AS locale, c.trial AS trial, '.$sql->daysBetween($sql->storedDate('s.date'), 'c.reg').' AS d '
            .'FROM c JOIN learner_daily_stats s ON s.learner_id = c.id WHERE s.reviews_count > 0 AND s.date >= ?'
            .') x WHERE x.d BETWEEN ? AND ? GROUP BY x.wk, x.locale, x.trial',
            [...$cteBindings, ...$bindings, $lowerBound, 1, max(self::DAYS)],
        ));
    }

    /**
     * @param  list<array{CarbonImmutable, CarbonImmutable}>  $weeks
     * @param  list<stdClass>  $cohortRows
     * @param  list<stdClass>  $retentionRows
     * @return array{cohorts: list<array<string, mixed>>, segments: array<string, mixed>}
     */
    private function assemble(array $weeks, array $cohortRows, array $retentionRows): array
    {
        $keys = array_map(static fn (int $day): string => 'd'.$day, self::DAYS);
        $keys[] = 'within';

        $perWeek = [];
        foreach (array_keys($weeks) as $index) {
            $perWeek[$index] = new RetentionWeek($keys);
        }

        foreach ($cohortRows as $row) {
            $perWeek[(int) $row->wk]->addCohort(
                (string) $row->locale,
                (int) $row->trial,
                (int) $row->size,
                (int) $row->onboarded,
                (int) $row->started,
                $row->elapsed === null ? null : (int) $row->elapsed,
            );
        }

        foreach ($retentionRows as $row) {
            $perWeek[(int) $row->wk]->addRetention(
                (string) $row->locale,
                (int) $row->trial,
                array_combine($keys, array_map(static fn (string $key): int => (int) $row->{$key}, $keys)),
            );
        }

        $cohorts = [];
        foreach ($weeks as $index => [$start]) {
            $week = $perWeek[$index];
            $retention = [];
            foreach ($keys as $key) {
                $retention[$key] = self::matured($week->elapsed, $key) ? self::cell($week->hits[$key], $week->size) : null;
            }

            $cohorts[] = [
                'week_start' => $start->toDateString(),
                'week_end' => $start->addDays(6)->toDateString(),
                'current' => $index === 0,
                'size' => $week->size,
                'onboarded' => $week->onboarded,
                'started' => $week->started,
                'started_pct' => self::pct($week->started, $week->size),
                'retention' => $retention,
            ];
        }

        return ['cohorts' => $cohorts, 'segments' => $this->segments($perWeek)];
    }

    /**
     * D1 and D7 of the last SEGMENT_WEEKS weeks by language and by trial. A week counts towards a day only when that day has
     * ended for its whole cohort; the figure is the sum over such weeks (a dash when there is none).
     *
     * @param  array<int, RetentionWeek>  $perWeek
     * @return array<string, mixed>
     */
    private function segments(array $perWeek): array
    {
        $groups = [
            'locale' => ['ru' => 'locale:ru', 'uz' => 'locale:uz'],
            'trial' => ['yes' => 'trial:yes', 'no' => 'trial:no'],
        ];
        $out = ['weeks' => self::SEGMENT_WEEKS];

        foreach ($groups as $group => $members) {
            $out[$group] = [];

            foreach ($members as $key => $segment) {
                $size = 0;
                $count = ['d1' => 0, 'd7' => 0];
                $of = ['d1' => 0, 'd7' => 0];

                for ($index = 0; $index < self::SEGMENT_WEEKS; $index++) {
                    $week = $perWeek[$index];
                    $weekSize = $week->segmentSizes[$segment] ?? 0;
                    $size += $weekSize;

                    foreach (['d1', 'd7'] as $day) {
                        if (self::matured($week->elapsed, $day)) {
                            $count[$day] += $week->segmentHits[$segment][$day] ?? 0;
                            $of[$day] += $weekSize;
                        }
                    }
                }

                $out[$group][] = [
                    'key' => $key,
                    'size' => $size,
                    'd1' => self::cell($count['d1'], $of['d1']),
                    'd7' => self::cell($count['d7'], $of['d7']),
                ];
            }
        }

        return $out;
    }

    /** Whether day N (`d7`; `within` = the 7th) has ended for the whole cohort: its youngest learner lived through more than N days. */
    private static function matured(?int $elapsed, string $key): bool
    {
        $day = $key === 'within' ? self::WITHIN_DAYS : (int) substr($key, 1);

        return $elapsed !== null && $elapsed > $day;
    }

    // -------------------------------------------------------------------------------------------------------------------
    // First session
    // -------------------------------------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function firstSession(RetentionSql $sql, CarbonImmutable $now): array
    {
        $since = $now->subDays(self::FIRST_SESSION_DAYS);
        $nowDb = self::db($now);

        // The round of hard words (docs/06 §14) is activity (its grades are in `learner_daily_stats`) but not a "session" of this
        // report: never the learner's first session and never their second (a constant of the code, passed as a binding).
        $cte = 'f AS (SELECT l.id AS learner_id, (SELECT MIN(a.id) FROM training_sessions a WHERE a.learner_id = l.id AND a.mode <> ?) AS s1 '
            ."FROM learners l WHERE l.deleted_at IS NULL AND l.created_at >= {$sql->ts()} AND l.created_at <= {$sql->ts()})";
        $cteBindings = [TrainingSession::MODE_HARD_REVIEW, self::db($since), $nowDb];

        $day = 86400;
        $dayAgo = self::db($now->subSeconds($day));
        $weekAgo = self::db($now->subSeconds(7 * $day));
        $gap = self::ROUND_GAP_MINUTES * 60;
        $gapAgo = self::db($now->subSeconds($gap));

        $totals = DB::selectOne(
            "WITH {$cte}, g AS (SELECT f.learner_id AS learner_id, f.s1 AS s1, "
            .'(SELECT MIN(r.id) FROM training_sessions r WHERE r.learner_id = f.learner_id AND r.mode <> ? AND r.id > f.s1) AS s2 FROM f) '
            .'SELECT COUNT(*) AS registered, '
            .'SUM(CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END) AS opened, '
            .'SUM(CASE WHEN a.done_reason = ? THEN 1 ELSE 0 END) AS finished, '
            ."SUM(CASE WHEN a.status = 'open' AND (a.expires_at IS NULL OR a.expires_at > {$sql->ts()}) THEN 1 ELSE 0 END) AS in_progress, "
            ."SUM(CASE WHEN a.id IS NOT NULL AND a.started_at <= {$sql->ts()} THEN 1 ELSE 0 END) AS of_24h, "
            .'SUM(CASE WHEN a.id IS NOT NULL AND a.started_at <= '.$sql->ts().' AND b.id IS NOT NULL AND b.started_at <= '.$sql->shift('a.started_at', $sql->int()).' THEN 1 ELSE 0 END) AS again_24h, '
            ."SUM(CASE WHEN a.id IS NOT NULL AND a.started_at <= {$sql->ts()} THEN 1 ELSE 0 END) AS of_7d, "
            .'SUM(CASE WHEN a.id IS NOT NULL AND a.started_at <= '.$sql->ts().' AND b.id IS NOT NULL AND b.started_at <= '.$sql->shift('a.started_at', $sql->int()).' THEN 1 ELSE 0 END) AS again_7d, '
            ."SUM(CASE WHEN a.id IS NOT NULL AND a.started_at <= {$sql->ts()} THEN 1 ELSE 0 END) AS of_round, "
            .'SUM(CASE WHEN a.id IS NOT NULL AND a.started_at <= '.$sql->ts().' AND r.id IS NOT NULL AND r.started_at < '.$sql->shift('a.started_at', $sql->int()).' THEN 1 ELSE 0 END) AS round_again '
            .'FROM g LEFT JOIN training_sessions a ON a.id = g.s1 LEFT JOIN training_sessions r ON r.id = g.s2 '
            // The "second session" is the first one that began a round-gap or more after the first (by id: they grow with time).
            .'LEFT JOIN training_sessions b ON b.id = (SELECT MIN(c.id) FROM training_sessions c WHERE c.learner_id = g.learner_id AND c.mode <> ? AND c.id > g.s1 AND c.started_at >= '.$sql->shift('a.started_at', $sql->int()).')',
            [
                ...$cteBindings,
                TrainingSession::MODE_HARD_REVIEW,
                TrainingSession::DONE_FINISHED,
                $nowDb,
                $dayAgo,
                $dayAgo, $day,
                $weekAgo,
                $weekAgo, 7 * $day,
                $gapAgo,
                $gapAgo, $gap,
                TrainingSession::MODE_HARD_REVIEW, $gap,
            ],
        );

        // One row per distinct value, not per learner: cards graded in the first session, and whole seconds of active time.
        $histogram = DB::select(
            "WITH {$cte}, per AS (SELECT f.s1 AS sid, COUNT(r.id) AS cards, COALESCE(SUM(r.time_spent_ms), 0) / ? AS seconds "
            .'FROM f LEFT JOIN training_reviews r ON r.training_session_id = f.s1 WHERE f.s1 IS NOT NULL GROUP BY f.s1) '
            ."SELECT 'cards' AS kind, cards AS value, COUNT(*) AS learners FROM per GROUP BY cards "
            ."UNION ALL SELECT 'seconds' AS kind, seconds AS value, COUNT(*) AS learners FROM per WHERE cards > 0 GROUP BY seconds",
            [...$cteBindings, 1000],
        );

        $cards = [];
        $seconds = [];
        foreach ($histogram as $row) {
            if ($row->kind === 'cards') {
                $cards[(int) $row->value] = (int) $row->learners;
            } else {
                $seconds[(int) $row->value] = (int) $row->learners;
            }
        }

        $registered = (int) ($totals->registered ?? 0);
        $opened = (int) ($totals->opened ?? 0);
        $finished = (int) ($totals->finished ?? 0);
        $inProgress = (int) ($totals->in_progress ?? 0);
        $abandoned = max(0, $opened - $finished - $inProgress);

        return [
            'since' => $since->toDateString(),
            'registered' => $registered,
            'opened' => self::cell($opened, $registered),
            'finished' => self::cell($finished, $opened),
            'abandoned' => self::cell($abandoned, $opened),
            'in_progress' => $inProgress,
            'median_cards' => self::median($cards),
            'median_seconds' => self::median($seconds),
            'second_24h' => self::cell((int) ($totals->again_24h ?? 0), (int) ($totals->of_24h ?? 0)),
            'second_7d' => self::cell((int) ($totals->again_7d ?? 0), (int) ($totals->of_7d ?? 0)),
            'round_again' => self::cell((int) ($totals->round_again ?? 0), (int) ($totals->of_round ?? 0)),
        ];
    }

    /**
     * Median of a histogram `value => how many`; null when it is empty.
     *
     * @param  array<int, int>  $histogram
     */
    private static function median(array $histogram): ?float
    {
        $total = array_sum($histogram);
        if ($total === 0) {
            return null;
        }

        ksort($histogram);
        $lower = intdiv($total + 1, 2);
        $upper = intdiv($total + 2, 2);
        $seen = 0;
        $low = $high = null;

        foreach ($histogram as $value => $count) {
            $seen += $count;
            $low ??= $seen >= $lower ? $value : null;
            if ($seen >= $upper) {
                $high = $value;
                break;
            }
        }

        return ((int) $low + (int) $high) / 2;
    }

    // -------------------------------------------------------------------------------------------------------------------

    /** @return array{count: int, of: int, pct: float}|null null when nobody is in the base (a dash, not 0 %) */
    private static function cell(int $count, int $of): ?array
    {
        $pct = self::pct($count, $of);

        return $pct === null ? null : ['count' => $count, 'of' => $of, 'pct' => $pct];
    }

    private static function pct(int $count, int $of): ?float
    {
        return $of > 0 ? round($count / $of * 100, 1) : null;
    }

    /** A moment as the database holds it: UTC `Y-m-d H:i:s`. */
    private static function db(CarbonInterface $moment): string
    {
        return $moment->utc()->format('Y-m-d H:i:s');
    }
}
