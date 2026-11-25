<?php

declare(strict_types=1);

namespace App\Domain\Cup\Support;

use App\Domain\Cup\DTO\BestResult;
use App\Domain\Cup\DTO\DayScore;

/**
 * The arithmetic of a cup (docs/33 §5.5), pure:
 *
 * - a day is worth `round + duel`, each clamped to its cap (20 and 5) - so a day never exceeds 25 whatever the rows say;
 * - the total is the sum of the best `countedDays` days: by the day's sum (desc), then its round part (desc) - among equal sums the
 *   days richer in rounds count, which maximizes the tie-break at once - then the day number (asc), so the choice is deterministic;
 * - a late participant simply has fewer days: the best 5 of what he played;
 * - places: by total (desc), then round points of the counted days (desc); a full tie shares the place and the next one is skipped
 *   (1, 1, 3).
 */
final class CupScoring
{
    public const ROUND_CAP = 20;

    public const DUEL_CAP = 5;

    public const DAY_CAP = self::ROUND_CAP + self::DUEL_CAP;

    /** @param list<DayScore> $days */
    public static function best(array $days, int $countedDays): BestResult
    {
        $clamped = [];
        foreach ($days as $day) {
            $clamped[] = new DayScore($day->day, max(0, min(self::ROUND_CAP, $day->round)), max(0, min(self::DUEL_CAP, $day->duel)));
        }

        usort($clamped, static fn (DayScore $a, DayScore $b): int => [$b->total(), $b->round, $a->day] <=> [$a->total(), $a->round, $b->day]);

        $total = 0;
        $round = 0;
        $counted = [];
        foreach (array_slice($clamped, 0, max(0, $countedDays)) as $day) {
            $total += $day->total();
            $round += $day->round;
            $counted[] = $day->day;
        }
        sort($counted);

        return new BestResult($total, $round, $counted);
    }

    /**
     * Standard competition ranking ("1224"): equal (total, round) share the place, the next place skips.
     *
     * @param  array<int, array{total: int, round: int}>  $scores  id => scores
     * @return array<int, int> id => place, in the order of the table (ties by id, so the order is stable)
     */
    public static function ranks(array $scores): array
    {
        $ids = array_keys($scores);
        usort($ids, static fn (int $a, int $b): int => [$scores[$b]['total'], $scores[$b]['round'], $a] <=> [$scores[$a]['total'], $scores[$a]['round'], $b]);

        $ranks = [];
        $place = 0;
        $previous = null;
        foreach ($ids as $position => $id) {
            $key = [$scores[$id]['total'], $scores[$id]['round']];
            if ($key !== $previous) {
                $place = $position + 1;
                $previous = $key;
            }
            $ranks[$id] = $place;
        }

        return $ranks;
    }
}
