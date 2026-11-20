<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Domain\Analytics\Services\ClientTimingReport;
use App\Domain\Analytics\Services\RetentionReportService;
use App\Domain\Analytics\Services\StudyTodayStats;
use App\Domain\Analytics\Support\WilsonInterval;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Services\BillingFunnelService;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Organizations\Services\OrgActivationStats;
use App\Jobs\WarmOpenSpeedJob;
use Carbon\CarbonImmutable;

/**
 * The tab «Учёба» of the dashboard of `/admin` (the design of 2026-11): the four KPI cards and the blocks under them, read from
 * the figures the platform already keeps — the retention report (activation in 24 hours, D7 by week, the first day of a cohort),
 * «Учились сегодня», the activation of the centers, the Pro funnel and the open speed of the Mini App. This class only picks and
 * compares: which cohort is the latest one whose figure is known, the change against the cohort before, the 95 % interval. Every
 * source is cached on its own ({@see WidgetCache}), so a warm open of the dashboard runs no query.
 *
 * The open speed is the exception to "computed by the first reader": it reads up to {@see ClientTimingReport::MAX_ROWS} timing marks
 * and decodes their JSON in PHP (≈ 0.5 s on 100 000 marks, docs/20 §1.13), too slow for the request of a widget. It is computed
 * off the request — {@see WarmOpenSpeedJob} on the schedule (routes/console.php) — into `admin:widget:client_timing:v1:all:d7`, and
 * the dashboard only reads that entry; when it is missing the widget says the figure is being computed and queues the job (unique,
 * so many opens queue one), it never waits for it.
 *
 * @phpstan-type Cell array{count: int, of: int, pct: float}
 * @phpstan-type Measure array{n: int, p50: int|null, p75: int|null, p95: int|null}
 */
final class DashboardOverview
{
    /** The window of the open speed, days. */
    public const TIMING_DAYS = 7;

    public const TIMING_WIDGET = 'client_timing';

    /**
     * How long the warmed open speed lives (seconds): the schedule rewrites it every 30 minutes, so a missed run or two still
     * leaves a figure; past it the widget queues a recompute.
     */
    public const TIMING_TTL_SECONDS = 3 * 3600;

    /** The platforms of the line «время до первой кнопки», in its order. */
    public const TIMING_PLATFORMS = ['android', 'ios'];

    /** Centers in the block «Центры: до первого задания». */
    public const CENTERS_SHOWN = 4;

    /** @var list<array<string, mixed>>|null */
    private ?array $cohorts = null;

    public function __construct(
        private readonly RetentionReportService $retention,
        private readonly StudyTodayStats $today,
        private readonly OrgActivationStats $centers,
        private readonly BillingFunnelService $funnel,
        private readonly ClientTimingReport $timing,
    ) {}

    /** @return array{studied: int, goal_met: int, week_ago: int, change: int|null} */
    public function studyToday(): array
    {
        $figures = $this->today->figures();

        return [
            'studied' => $figures['studied'],
            'goal_met' => $figures['goal_met'],
            'week_ago' => $figures['week_ago'],
            'change' => StudyTodayStats::change($figures['studied'], $figures['week_ago']),
        ];
    }

    /**
     * The activation of the latest registration week whose 24 hours are over for everybody, with its cuts; null while no week is.
     *
     * @return array{week_start: string, week_end: string, all: Cell, ru: Cell|null, uz: Cell|null, class: Cell|null}|null
     */
    public function activation(): ?array
    {
        $cohort = $this->latestCohort(static fn (array $cohort): bool => is_array($cohort['activation']['all'] ?? null));
        if ($cohort === null) {
            return null;
        }

        $cell = static fn (string $cut): ?array => self::cell($cohort['activation'][$cut] ?? null);

        /** @var Cell $all */
        $all = $cell('all');

        return [
            'week_start' => (string) $cohort['week_start'],
            'week_end' => (string) $cohort['week_end'],
            'all' => $all,
            'ru' => $cell('ru'),
            'uz' => $cell('uz'),
            'class' => $cell('class'),
        ];
    }

    /**
     * D7 of the latest week where it is known, the change against the week before it in percentage points, and the Wilson 95 %
     * interval; null while no week has a D7.
     *
     * @return array{week_start: string, week_end: string, cell: Cell, change: float|null, low: float|null, high: float|null}|null
     */
    public function returnDay7(): ?array
    {
        $points = $this->day7Trend();
        $last = array_pop($points);
        if ($last === null) {
            return null;
        }

        $before = $points === [] ? null : $points[array_key_last($points)];
        $interval = WilsonInterval::of($last['cell']['count'], $last['cell']['of']);

        return [
            'week_start' => $last['week_start'],
            'week_end' => $last['week_end'],
            'cell' => $last['cell'],
            'change' => $before === null ? null : round($last['cell']['pct'] - $before['cell']['pct'], 1),
            'low' => $interval['low'] ?? null,
            'high' => $interval['high'] ?? null,
        ];
    }

    /**
     * D7 by registration week, the oldest first, only the weeks where it is known.
     *
     * @return list<array{week_start: string, week_end: string, cell: Cell}>
     */
    public function day7Trend(): array
    {
        $points = [];
        foreach (array_reverse($this->cohorts()) as $cohort) {
            $cell = self::cell($cohort['retention']['d7'] ?? null);
            if ($cell !== null) {
                $points[] = ['week_start' => (string) $cohort['week_start'], 'week_end' => (string) $cohort['week_end'], 'cell' => $cell];
            }
        }

        return $points;
    }

    /**
     * The share of all those weeks together: the dashed line of the chart.
     *
     * @param  list<array{week_start: string, week_end: string, cell: Cell}>  $points
     */
    public static function day7Average(array $points): ?float
    {
        $count = array_sum(array_map(static fn (array $point): int => $point['cell']['count'], $points));
        $of = array_sum(array_map(static fn (array $point): int => $point['cell']['of'], $points));

        return $of > 0 ? round($count / $of * 100, 1) : null;
    }

    /**
     * The first 24 hours of the same cohort as {@see activation()}: the deepest step of each learner.
     *
     * @return array{week_start: string, week_end: string, size: int, answered: int, opened: int, onboarded: int, rest: int}|null
     */
    public function firstDay(): ?array
    {
        $cohort = $this->latestCohort(static fn (array $cohort): bool => is_array($cohort['first_day'] ?? null) && (int) ($cohort['first_day']['size'] ?? 0) > 0);
        if ($cohort === null) {
            return null;
        }

        $day = $cohort['first_day'];
        $int = static fn (string $key): int => is_numeric($day[$key] ?? null) ? (int) $day[$key] : 0;

        return [
            'week_start' => (string) $cohort['week_start'],
            'week_end' => (string) $cohort['week_end'],
            'size' => $int('size'),
            'answered' => $int('answered'),
            'opened' => $int('opened'),
            'onboarded' => $int('onboarded'),
            'rest' => $int('rest'),
        ];
    }

    /**
     * p75 of «the primary button of Сегодня is ready» by platform over the last {@see TIMING_DAYS} days, from the warmed entry only
     * (no query). Null while there is no entry: then the recompute is queued ({@see WarmOpenSpeedJob}) and not waited for.
     *
     * @return array<string, Measure>|null by {@see TIMING_PLATFORMS}, a platform without opens left out
     */
    public function openSpeed(): ?array
    {
        $report = PureCache::store()->get(self::timingKey());
        if (! is_array($report)) {
            WarmOpenSpeedJob::dispatch();

            return null;
        }

        $platforms = is_array($report['platforms'] ?? null) ? $report['platforms'] : [];
        $out = [];
        foreach (self::TIMING_PLATFORMS as $platform) {
            $measure = $platforms[$platform] ?? null;
            if (! is_array($measure) || ! is_numeric($measure['n'] ?? null) || (int) $measure['n'] === 0) {
                continue;
            }
            $nullable = static fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null;
            $out[$platform] = ['n' => (int) $measure['n'], 'p50' => $nullable($measure['p50'] ?? null), 'p75' => $nullable($measure['p75'] ?? null), 'p95' => $nullable($measure['p95'] ?? null)];
        }

        return $out;
    }

    /** Computes the open speed from the timing marks and writes it for {@see openSpeed()}: the job of the schedule, never a request. */
    public function warmOpenSpeed(?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        PureCache::store()->put(self::timingKey(), $this->timing->byPlatform($now->subDays(self::TIMING_DAYS), $now), self::TIMING_TTL_SECONDS);
    }

    /** `admin:widget:client_timing:v1:all:d7`: the array {@see ClientTimingReport::byPlatform()} returns, scalars only. */
    public static function timingKey(): string
    {
        return WidgetCache::key(self::TIMING_WIDGET, 'all', 'd'.self::TIMING_DAYS);
    }

    /**
     * «Центры: до первого задания»: the centers still waiting for an assignment a learner completed (the oldest first), then the
     * latest ones that got there, with how long it took; the median over the centers that did.
     *
     * @return array{median: int|null, reached: int, centers: int, rows: list<array{id: int, name: string, waiting: bool, seconds: int}>}
     */
    public function centers(): array
    {
        $figures = $this->centers->figures();
        $active = array_values(array_filter($figures['rows'], static fn (array $row): bool => $row['status'] === 'active'));

        $waiting = array_values(array_filter($active, static fn (array $row): bool => $row['completed'] === null));
        usort($waiting, static fn (array $a, array $b): int => [-$a['age'], $a['id']] <=> [-$b['age'], $b['id']]);

        $done = array_values(array_filter($active, static fn (array $row): bool => $row['completed'] !== null));
        // The youngest centers that got there first: the most recent result.
        usort($done, static fn (array $a, array $b): int => [$a['age'], $a['id']] <=> [$b['age'], $b['id']]);

        $rows = [];
        foreach ([...$waiting, ...$done] as $row) {
            $rows[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'waiting' => $row['completed'] === null,
                'seconds' => $row['completed'] ?? $row['age'],
            ];
        }

        return [
            'median' => $figures['steps']['completed']['median'],
            'reached' => $figures['steps']['completed']['reached'],
            'centers' => $figures['centers'],
            'rows' => array_slice($rows, 0, self::CENTERS_SHOWN),
        ];
    }

    /**
     * «Путь к Pro · Stars»: the funnel of the Pro funnel report for Telegram Stars over all locales, step by step.
     *
     * @return array{days: int, viewed: int, started: int, paid: int, studied: int, matured: int}|null null while nobody saw an offer
     */
    public function proPath(): ?array
    {
        $report = $this->funnel->report();

        foreach ($report['funnel'] as $row) {
            if ($row['provider'] === SubscriptionProvider::TelegramStars->value && $row['locale'] === BillingFunnelService::ALL) {
                if ($row['viewed'] === 0 && $row['started'] === 0) {
                    return null;
                }

                return [
                    'days' => $report['days'],
                    'viewed' => $row['viewed'],
                    'started' => $row['started'],
                    'paid' => $row['paid'],
                    'studied' => $row['studied'],
                    'matured' => max(0, $row['paid'] - $row['maturing']),
                ];
            }
        }

        return null;
    }

    /**
     * The newest cohort (the current week first) that `$known` accepts.
     *
     * @param  callable(array<string, mixed>): bool  $known
     * @return array<string, mixed>|null
     */
    private function latestCohort(callable $known): ?array
    {
        foreach ($this->cohorts() as $cohort) {
            if ($known($cohort)) {
                return $cohort;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> the cohorts of the retention report, the current week first (read once per instance) */
    private function cohorts(): array
    {
        if ($this->cohorts === null) {
            $cohorts = $this->retention->report()['cohorts'] ?? [];
            $this->cohorts = is_array($cohorts) ? array_values(array_filter($cohorts, 'is_array')) : [];
        }

        return $this->cohorts;
    }

    /** @return Cell|null */
    private static function cell(mixed $cell): ?array
    {
        if (! is_array($cell) || ! is_numeric($cell['count'] ?? null) || ! is_numeric($cell['of'] ?? null) || ! is_numeric($cell['pct'] ?? null)) {
            return null;
        }

        return ['count' => (int) $cell['count'], 'of' => (int) $cell['of'], 'pct' => (float) $cell['pct']];
    }
}
