<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Services\Scoring\ScoreTodayService;
use App\Domain\Learning\DTO\ScoreDayCount;
use Carbon\CarbonImmutable;

/**
 * "Today N of 100" (docs/32 §8.2, `GET /today.score_today` and `POST /finish.score_session.today`): the learner's rating day as it
 * stands - the weekly count (the one the screen shows) with its cap and slots, what is left of the cap, when the rating day ends
 * (midnight of the league zone), and the monthly count only when it differs from the weekly one (another category or sum). A count
 * still on the v1 rules has no limits; the whole block exists only while a count of the day is on v2 ({@see ScoreTodayService}).
 */
final readonly class ScoreToday
{
    public function __construct(
        public ScoreDayCount $week,
        public ScoreDayCount $month,
        public CarbonImmutable $dayEndsAt,
    ) {}

    /**
     * @return array{points: int, cap: int|null, category: string, new: array{0: int, 1: int|null}, review: array{0: int, 1: int|null}, remaining: int|null, day_ends_at: string, month: array{points: int, cap: int|null, category: string, new: array{0: int, 1: int|null}, review: array{0: int, 1: int|null}, remaining: int|null}|null}
     */
    public function toArray(): array
    {
        $week = $this->week->version >= 2 ? $this->week : $this->month;
        $month = $this->month;
        $differs = $week !== $month && $month->version >= 2
            && ($month->category !== $week->category || $month->points !== $week->points || $month->cap !== $week->cap);

        return [
            ...self::count($week),
            'day_ends_at' => $this->dayEndsAt->utc()->toIso8601String(),
            'month' => $differs ? self::count($month) : null,
        ];
    }

    /** @return array{points: int, cap: int|null, category: string, new: array{0: int, 1: int|null}, review: array{0: int, 1: int|null}, remaining: int|null} */
    private static function count(ScoreDayCount $count): array
    {
        return [...$count->toArray(), 'remaining' => $count->cap === null ? null : max(0, $count->cap - $count->points)];
    }
}
