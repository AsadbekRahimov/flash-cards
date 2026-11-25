<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Learners\Support\Regions;
use App\Models\Learner;

/**
 * «Ученики по регионам» of the dashboard (docs/35 §2.4, R5): the learners who finished the onboarding, not deleted, by
 * `learners.region_code` - the number and the share of each region, `none` for those without one. One grouped statement (the
 * index `learners_region_code_idx`; the rows are at most 16), cached as an array of scalars ({@see WidgetCache},
 * `admin:widget:regions:v1:all`, fresh for {@see FRESH_SECONDS}).
 */
final class RegionDistributionStats
{
    public const WIDGET = 'regions';

    public const FRESH_SECONDS = 600;

    /** The row of the learners without a region. */
    public const NONE = 'none';

    public function __construct(private readonly WidgetCache $cache) {}

    /** @return array{total: int, rows: list<array{id: string, region: string, learners: int, pct: float}>} */
    public function current(): array
    {
        return self::normalize($this->cache->remember(self::WIDGET, 'all', self::FRESH_SECONDS, fn (): array => $this->compute()));
    }

    /**
     * From the database (no cache): the regions in the order of the Russian pickers, then `other`, then `none`; a stored code that
     * is not in the list any more after them. Regions nobody chose are left out.
     *
     * @return array{total: int, rows: list<array{id: string, region: string, learners: int, pct: float}>}
     */
    public function compute(): array
    {
        $records = Learner::query()
            ->where('onboarding_state', OnboardingService::DONE)
            ->groupBy('region_code')
            ->select('region_code')
            ->selectRaw('COUNT(*) AS learners')
            ->toBase()
            ->get();

        $counts = [];
        foreach ($records as $record) {
            $code = is_string($record->region_code ?? null) ? $record->region_code : self::NONE;
            $counts[$code] = ($counts[$code] ?? 0) + (int) $record->learners;
        }

        $total = array_sum($counts);
        $order = [...Regions::ordered('ru'), Regions::OTHER, self::NONE];
        $codes = [...array_values(array_intersect($order, array_keys($counts))), ...array_values(array_diff(array_map('strval', array_keys($counts)), $order))];

        $rows = [];
        foreach ($codes as $code) {
            $rows[] = [
                'id' => $code,
                'region' => $code,
                'learners' => $counts[$code],
                'pct' => $total > 0 ? round($counts[$code] / $total * 100, 1) : 0.0,
            ];
        }

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * Cached figures back to their types (docs/19 §3 rule 11: Redis may hand a number back as a string).
     *
     * @param  array<array-key, mixed>  $figures
     * @return array{total: int, rows: list<array{id: string, region: string, learners: int, pct: float}>}
     */
    private static function normalize(array $figures): array
    {
        $rows = [];
        foreach (is_array($figures['rows'] ?? null) ? $figures['rows'] : [] as $row) {
            if (! is_array($row) || ! is_scalar($row['region'] ?? null) || ! is_numeric($row['learners'] ?? null) || ! is_numeric($row['pct'] ?? null)) {
                continue;
            }
            $region = (string) $row['region'];
            $rows[] = ['id' => $region, 'region' => $region, 'learners' => (int) $row['learners'], 'pct' => (float) $row['pct']];
        }

        $total = $figures['total'] ?? 0;

        return ['total' => is_numeric($total) ? (int) $total : 0, 'rows' => $rows];
    }
}
