<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Domain\Administration\Services\OrganizationGroupLinker;
use App\Domain\Administration\Services\WidgetCache;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How fast a learning center gets to its first result (audit 2026-11-18, «4. Преподаватель, центр и подписка»): per organization
 * the time from `organizations.created_at` to
 *  - the first group link: the earliest `organization.linked_group` audit row of the organization ({@see OrganizationGroupLinker},
 *    written by both the owner's link and the admin's); `telegram_groups` has no link timestamp, so a group linked without an
 *    audit row (seeded, or by hand in SQL) leaves it empty;
 *  - the first assignment created: the earliest `assignments.created_at` of the organization;
 *  - the first assignment completed by a learner: the earliest `assignment_results.completed_at` of an assignment of the
 *    organization (the metric of the audit);
 * plus the median of each over the centers that reached the step, and the centers with no completed assignment yet, oldest first
 * (the ones to walk through with the teacher). An unlink or a closed assignment does not undo a step: it was reached.
 *
 * One statement (three correlated `MIN` subqueries on indexed columns: `audit_logs (entity_type, entity_id)`,
 * `assignments (organization_id, …)`, `assignment_results (assignment_id, …)`), whatever the number of centers; the rows are the
 * organizations themselves (hundreds), the durations are worked out in PHP. The result is an array of scalars in the pure cache
 * ({@see WidgetCache}, `admin:widget:org_activation:v1:all`, fresh for {@see FRESH_SECONDS}).
 */
final class OrgActivationStats
{
    public const WIDGET = 'org_activation';

    public const FRESH_SECONDS = 600;

    /** The steps, in the order of the columns. */
    public const STEPS = ['link', 'assignment', 'completed'];

    public function __construct(private readonly WidgetCache $cache) {}

    /**
     * The figures from the cache (computed at most once per {@see FRESH_SECONDS}, a stale entry is served while it is recomputed).
     *
     * @return array{
     *     generated_at: string,
     *     centers: int,
     *     waiting: int,
     *     steps: array<string, array{reached: int, median: int|null}>,
     *     rows: list<array{id: int, name: string, status: string, created_at: string, age: int, link: int|null, assignment: int|null, completed: int|null}>,
     * }
     */
    public function figures(): array
    {
        $figures = $this->cache->remember(self::WIDGET, 'all', self::FRESH_SECONDS, fn (): array => $this->compute(CarbonImmutable::now()));

        return self::normalize($figures);
    }

    /**
     * The figures as of `$now`, from the database (no cache). Durations are whole seconds, never negative.
     *
     * @return array{
     *     generated_at: string,
     *     centers: int,
     *     waiting: int,
     *     steps: array<string, array{reached: int, median: int|null}>,
     *     rows: list<array{id: int, name: string, status: string, created_at: string, age: int, link: int|null, assignment: int|null, completed: int|null}>,
     * }
     */
    public function compute(CarbonImmutable $now): array
    {
        $records = DB::table('organizations as o')
            ->select(['o.id', 'o.name', 'o.status', 'o.created_at'])
            ->selectSub(fn (Builder $q) => $q->from('audit_logs as al')
                ->selectRaw('MIN(al.created_at)')
                ->where('al.entity_type', Organization::class)
                ->whereColumn('al.entity_id', 'o.id')
                ->where('al.action', OrganizationGroupLinker::ACTION_LINKED), 'first_link')
            ->selectSub(fn (Builder $q) => $q->from('assignments as a')
                ->selectRaw('MIN(a.created_at)')
                ->whereColumn('a.organization_id', 'o.id'), 'first_assignment')
            ->selectSub(fn (Builder $q) => $q->from('assignment_results as r')
                ->join('assignments as ra', 'ra.id', '=', 'r.assignment_id')
                ->selectRaw('MIN(r.completed_at)')
                ->whereColumn('ra.organization_id', 'o.id')
                ->whereNotNull('r.completed_at'), 'first_completed')
            ->orderBy('o.created_at')
            ->orderBy('o.id')
            ->get();

        $rows = [];
        foreach ($records as $record) {
            $created = self::moment($record->created_at) ?? $now;

            $rows[] = [
                'id' => (int) $record->id,
                'name' => (string) $record->name,
                'status' => (string) $record->status,
                'created_at' => $created->utc()->format('Y-m-d H:i:s'),
                'age' => max(0, $now->getTimestamp() - $created->getTimestamp()),
                'link' => self::since($created, $record->first_link),
                'assignment' => self::since($created, $record->first_assignment),
                'completed' => self::since($created, $record->first_completed),
            ];
        }

        // Waiting first (no completed assignment), the oldest of them on top; then the others, the slowest first.
        usort($rows, static fn (array $a, array $b): int => [$a['completed'] !== null, -($a['completed'] ?? 0), $a['created_at'], $a['id']]
            <=> [$b['completed'] !== null, -($b['completed'] ?? 0), $b['created_at'], $b['id']]);

        $steps = [];
        foreach (self::STEPS as $step) {
            $values = array_values(array_filter(array_column($rows, $step), static fn (?int $value): bool => $value !== null));
            $steps[$step] = ['reached' => count($values), 'median' => self::median($values)];
        }

        return [
            'generated_at' => $now->toIso8601String(),
            'centers' => count($rows),
            'waiting' => count($rows) - $steps['completed']['reached'],
            'steps' => $steps,
            'rows' => $rows,
        ];
    }

    /**
     * Median of whole seconds, rounded to a whole second; null for none.
     *
     * @param  list<int>  $values
     */
    public static function median(array $values): ?int
    {
        $count = count($values);
        if ($count === 0) {
            return null;
        }

        sort($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }

    /** Seconds from the creation of the center to the step; null when the step was not reached. */
    private static function since(CarbonImmutable $created, mixed $moment): ?int
    {
        $at = self::moment($moment);

        return $at === null ? null : max(0, $at->getTimestamp() - $created->getTimestamp());
    }

    /** A timestamp as the database returns it (UTC, `Y-m-d H:i:s` text on sqlite, the same on PostgreSQL). */
    private static function moment(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, 'UTC') : null;
    }

    /**
     * Cached figures back to their types: a number may come back from Redis as a string (docs/19 §3 rule 11), and a value of
     * another shape is read as "no centers".
     *
     * @param  array<array-key, mixed>  $figures
     * @return array{
     *     generated_at: string,
     *     centers: int,
     *     waiting: int,
     *     steps: array<string, array{reached: int, median: int|null}>,
     *     rows: list<array{id: int, name: string, status: string, created_at: string, age: int, link: int|null, assignment: int|null, completed: int|null}>,
     * }
     */
    private static function normalize(array $figures): array
    {
        $int = static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0;
        $nullable = static fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null;

        $steps = [];
        foreach (self::STEPS as $step) {
            $raw = is_array($figures['steps'][$step] ?? null) ? $figures['steps'][$step] : [];
            $steps[$step] = ['reached' => $int($raw['reached'] ?? 0), 'median' => $nullable($raw['median'] ?? null)];
        }

        $rows = [];
        foreach (is_array($figures['rows'] ?? null) ? $figures['rows'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rows[] = [
                'id' => $int($row['id'] ?? 0),
                'name' => is_scalar($row['name'] ?? null) ? (string) $row['name'] : '',
                'status' => is_scalar($row['status'] ?? null) ? (string) $row['status'] : '',
                'created_at' => is_scalar($row['created_at'] ?? null) ? (string) $row['created_at'] : '',
                'age' => $int($row['age'] ?? 0),
                'link' => $nullable($row['link'] ?? null),
                'assignment' => $nullable($row['assignment'] ?? null),
                'completed' => $nullable($row['completed'] ?? null),
            ];
        }

        return [
            'generated_at' => is_string($figures['generated_at'] ?? null) ? $figures['generated_at'] : '',
            'centers' => $int($figures['centers'] ?? 0),
            'waiting' => $int($figures['waiting'] ?? 0),
            'steps' => $steps,
            'rows' => $rows,
        ];
    }
}
