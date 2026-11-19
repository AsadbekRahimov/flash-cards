<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Learning\Enums\ExerciseType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Which exercise formats learners actually get (audit 2026-11-18, «Что делать ученику в разные моменты», row 2): the share of
 * each `training_reviews.exercise` among the cards answered in the last {@see DAYS} days, for everybody and by the learner's
 * interface language — so that a format vanishing in one language (audio, say: files missing, sound off) shows as a gap
 * between the RU and UZ columns. A card counts when it was answered (a `training_reviews` row): a card shown and left without an
 * answer is not stored anywhere.
 *
 * One grouped statement over the window (`training_reviews (created_at, learner_id)` index, a join of `learners` by primary
 * key); the rows are (language × format), a few dozen. Cached as an array of scalars ({@see WidgetCache},
 * `admin:widget:exercise_mix:v1:all`, fresh for {@see FRESH_SECONDS}).
 */
final class ExerciseMixStats
{
    public const WIDGET = 'exercise_mix';

    public const FRESH_SECONDS = 600;

    public const DAYS = 7;

    /** The columns of the language cut; another language is only in "all". */
    public const LOCALES = ['ru', 'uz'];

    public function __construct(private readonly WidgetCache $cache) {}

    /**
     * @return array{totals: array<string, int>, rows: list<array{id: string, exercise: string, all: array{count: int, of: int, pct: float}|null, ru: array{count: int, of: int, pct: float}|null, uz: array{count: int, of: int, pct: float}|null}>}
     */
    public function lastWeek(): array
    {
        $figures = $this->cache->remember(self::WIDGET, 'all', self::FRESH_SECONDS, fn (): array => $this->compute(CarbonImmutable::now()));

        return self::normalize($figures);
    }

    /**
     * From the database (no cache): formats in the order of {@see ExerciseType}, an unknown stored value after them.
     *
     * @return array{totals: array<string, int>, rows: list<array{id: string, exercise: string, all: array{count: int, of: int, pct: float}|null, ru: array{count: int, of: int, pct: float}|null, uz: array{count: int, of: int, pct: float}|null}>}
     */
    public function compute(CarbonImmutable $now): array
    {
        $records = DB::table('training_reviews as r')
            ->join('learners as l', 'l.id', '=', 'r.learner_id')
            ->where('r.created_at', '>=', $now->subDays(self::DAYS)->utc()->format('Y-m-d H:i:s'))
            ->where('r.created_at', '<=', $now->utc()->format('Y-m-d H:i:s'))
            ->groupBy('l.interface_locale', 'r.exercise')
            ->select(['l.interface_locale as locale', 'r.exercise as exercise'])
            ->selectRaw('COUNT(*) AS cards')
            ->get();

        $counts = [];
        $totals = ['all' => 0, 'ru' => 0, 'uz' => 0];
        foreach ($records as $record) {
            $exercise = (string) $record->exercise;
            $locale = (string) $record->locale;
            $cards = (int) $record->cards;
            $columns = in_array($locale, self::LOCALES, true) ? ['all', $locale] : ['all'];

            foreach ($columns as $column) {
                $counts[$exercise][$column] = ($counts[$exercise][$column] ?? 0) + $cards;
                $totals[$column] += $cards;
            }
        }

        $order = array_map(static fn (ExerciseType $type): string => $type->value, ExerciseType::cases());
        $exercises = [...array_values(array_intersect($order, array_keys($counts))), ...array_values(array_diff(array_keys($counts), $order))];

        $rows = [];
        foreach ($exercises as $exercise) {
            $exercise = (string) $exercise;
            $row = ['id' => $exercise, 'exercise' => $exercise];
            foreach (['all', 'ru', 'uz'] as $column) {
                $count = $counts[$exercise][$column] ?? 0;
                $row[$column] = $totals[$column] > 0 ? ['count' => $count, 'of' => $totals[$column], 'pct' => round($count / $totals[$column] * 100, 1)] : null;
            }
            $rows[] = $row;
        }

        return ['totals' => $totals, 'rows' => $rows];
    }

    /**
     * Cached figures back to their types (docs/19 §3 rule 11: Redis may hand a number back as a string).
     *
     * @param  array<array-key, mixed>  $figures
     * @return array{totals: array<string, int>, rows: list<array{id: string, exercise: string, all: array{count: int, of: int, pct: float}|null, ru: array{count: int, of: int, pct: float}|null, uz: array{count: int, of: int, pct: float}|null}>}
     */
    private static function normalize(array $figures): array
    {
        $totals = [];
        foreach (['all', 'ru', 'uz'] as $column) {
            $value = $figures['totals'][$column] ?? 0;
            $totals[$column] = is_numeric($value) ? (int) $value : 0;
        }

        $cell = static fn (mixed $cell): ?array => is_array($cell) && is_numeric($cell['count'] ?? null) && is_numeric($cell['of'] ?? null) && is_numeric($cell['pct'] ?? null)
            ? ['count' => (int) $cell['count'], 'of' => (int) $cell['of'], 'pct' => (float) $cell['pct']]
            : null;

        $rows = [];
        foreach (is_array($figures['rows'] ?? null) ? $figures['rows'] : [] as $row) {
            if (! is_array($row) || ! is_scalar($row['exercise'] ?? null)) {
                continue;
            }
            $exercise = (string) $row['exercise'];
            $rows[] = ['id' => $exercise, 'exercise' => $exercise, 'all' => $cell($row['all'] ?? null), 'ru' => $cell($row['ru'] ?? null), 'uz' => $cell($row['uz'] ?? null)];
        }

        return ['totals' => $totals, 'rows' => $rows];
    }
}
