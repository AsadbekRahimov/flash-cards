<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How fast the open of the Mini App is for the learner (audit 2026-11-18, «Скорость для ученика»): p50 / p75 / p95 of the
 * `client.timing` offsets ({@see ClientTimingRecorder}) per cell "platform × cold / warm × class / solo", with the number of opens of the
 * cell. docs/ADMIN_GUIDE.md "Скорость открытия" explains how to read it.
 *
 * Measures, in milliseconds from the start of the open (the navigation start of the page):
 *  - `button_ready` - the primary button of «Сегодня» became usable (network, authentication and rendering included);
 *  - `understand` - `first_tap − button_ready`: how long the person needed to understand the screen (only opens with both marks,
 *    a tap before the button is a broken pair and is left out);
 *  - `first_answer_ack` - the server accepted the first answer;
 *  - `first_audio` - the first word began to sound.
 * Each measure is over the opens that have it (`n`); `opens` of a cell counts all its opens. A cell of fewer than {@see MIN_OPENS}
 * opens is marked `small`: its percentiles are shown, but are no ground for a decision.
 *
 * Segment: `class` when the open's `class` prop is true (an active member of a group linked to a center), `solo` when false, `unknown`
 * when the server had no `/today` snapshot to tell.
 *
 * Percentiles: linear interpolation between the closest ranks (`percentile_cont` of PostgreSQL, type 7 of Hyndman & Fan). They are
 * computed here, not in SQL, so that sqlite and PostgreSQL give the same numbers: the rows of the window are read by id in chunks of
 * {@see CHUNK}, the JSON props decoded in PHP (no JSON operator in SQL at all), at most {@see MAX_ROWS} opens - the oldest first; past it
 * the report says it was cut (`truncated`), narrow the window then. The scan uses the `(name, occurred_at)` index; every value is a binding.
 *
 * @phpstan-type Measure array{n: int, p50: int|null, p75: int|null, p95: int|null}
 * @phpstan-type Cell array{platform: string, start: string, segment: string, opens: int, small: bool, metrics: array<string, Measure>}
 * @phpstan-type Report array{from: string, to: string, opens: int, truncated: bool, cells: list<Cell>}
 */
final class ClientTimingReport
{
    /** The measures in the order of the report. */
    public const METRICS = ['button_ready', 'understand', 'first_answer_ack', 'first_audio'];

    /** Fewer opens in a cell than this: the cell is marked as too small. */
    public const MIN_OPENS = 30;

    /** At most this many opens are read (≈ 1 M opens of the app at a 10 % sample); ~20 MB of PHP arrays at the most. */
    public const MAX_ROWS = 100_000;

    private const CHUNK = 1_000;

    /** The label of a dimension summed over all its values. */
    public const ALL = 'all';

    /** @return Report */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var array<string, array{platform: string, start: string, segment: string, opens: int, values: array<string, list<int>>}> $cells */
        $cells = [];
        $read = 0;
        $truncated = false;

        $rows = DB::table('product_events')
            ->select(['id', 'props'])
            ->where('name', ProductEventName::ClientTiming->value)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $to)
            ->lazyById(self::CHUNK);

        foreach ($rows as $row) {
            if ($read >= self::MAX_ROWS) {
                $truncated = true;
                break;
            }
            $read++;

            $props = is_string($row->props) ? json_decode($row->props, true) : null;
            if (! is_array($props)) {
                continue;
            }

            $platform = is_string($props['platform'] ?? null) ? $props['platform'] : 'unknown';
            $start = ($props['cold'] ?? null) === true ? 'cold' : 'warm';
            $segment = match ($props['class'] ?? null) {
                true => 'class',
                false => 'solo',
                default => 'unknown',
            };
            $values = $this->values($props);

            foreach ([[$platform, $start, $segment], [self::ALL, self::ALL, self::ALL]] as [$p, $s, $g]) {
                $key = $p.'|'.$s.'|'.$g;
                $cells[$key] ??= ['platform' => $p, 'start' => $s, 'segment' => $g, 'opens' => 0, 'values' => []];
                $cells[$key]['opens']++;
                foreach ($values as $metric => $value) {
                    $cells[$key]['values'][$metric][] = $value;
                }
            }
        }

        // The total first, then the cells by platform, start and segment.
        uksort($cells, static fn (string $a, string $b): int => [! str_starts_with($a, self::ALL.'|'), $a] <=> [! str_starts_with($b, self::ALL.'|'), $b]);

        $out = [];
        foreach ($cells as $cell) {
            $metrics = [];
            foreach (self::METRICS as $metric) {
                $metrics[$metric] = $this->measure($cell['values'][$metric] ?? []);
            }
            $out[] = [
                'platform' => $cell['platform'],
                'start' => $cell['start'],
                'segment' => $cell['segment'],
                'opens' => $cell['opens'],
                'small' => $cell['opens'] < self::MIN_OPENS,
                'metrics' => $metrics,
            ];
        }

        return [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'opens' => $read,
            'truncated' => $truncated,
            'cells' => $out,
        ];
    }

    /**
     * The p-th percentile (0..100) of `$values` by linear interpolation between the closest ranks; null for no values.
     *
     * @param  list<int>  $values
     */
    public static function percentile(array $values, float $p): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $rank = ($p / 100) * (count($values) - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);

        return $values[$low] + ($values[$high] - $values[$low]) * ($rank - $low);
    }

    /**
     * @param  array<mixed>  $props
     * @return array<string, int> the measures this open has
     */
    private function values(array $props): array
    {
        $offset = static fn (string $key): ?int => is_int($props[$key] ?? null) && $props[$key] >= 0 ? $props[$key] : null;

        $values = [];
        foreach (['button_ready', 'first_answer_ack', 'first_audio'] as $metric) {
            $value = $offset($metric);
            if ($value !== null) {
                $values[$metric] = $value;
            }
        }

        $ready = $offset('button_ready');
        $tap = $offset('first_tap');
        if ($ready !== null && $tap !== null && $tap >= $ready) {
            $values['understand'] = $tap - $ready;
        }

        return $values;
    }

    /**
     * @param  list<int>  $values
     * @return array{n: int, p50: int|null, p75: int|null, p95: int|null}
     */
    private function measure(array $values): array
    {
        sort($values);
        $at = static function (float $p) use ($values): ?int {
            $value = self::percentile($values, $p);

            return $value === null ? null : (int) round($value);
        };

        return ['n' => count($values), 'p50' => $at(50), 'p75' => $at(75), 'p95' => $at(95)];
    }
}
