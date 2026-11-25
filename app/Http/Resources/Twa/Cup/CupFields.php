<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa\Cup;

use App\Domain\Cup\Enums\CupStatus;
use App\Domain\Cup\Support\CupAccess;
use App\Domain\Cup\Support\CupClock;
use App\Domain\Practice\Support\IsoTime;
use App\Models\Cup;
use Carbon\CarbonImmutable;

/**
 * The fields of a cup every resource of the cup shares (docs/04 A.23): what a member of the chat may see of it - never the code, the
 * seed, the plan of the words or a Telegram id. Times are ISO 8601 UTC with milliseconds; the borders of the days are local midnights
 * of `timezone`, the time of the summary is `HH:MM` there.
 */
final class CupFields
{
    /**
     * @return array{id: int, status: string, group_title: string, level: string, days: int, counted_days: int, round_tasks: int, round_minutes: int, timezone: string, starts_at: string|null, ends_at: string|null, day: int|null, summaries_enabled: bool, summary_time: string, paused: string|null}
     */
    public static function of(Cup $cup, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $day = null;
        if ($cup->starts_at !== null && $cup->status === CupStatus::Running) {
            $number = CupClock::dayNumber($cup->starts_at, $cup->timezone, $now);
            $day = $number >= 1 && $number <= $cup->days ? $number : null;
        }

        return [
            'id' => $cup->id,
            'status' => $cup->status->value,
            'group_title' => (string) $cup->telegramGroup->title,
            'level' => $cup->level,
            'days' => $cup->days,
            'counted_days' => $cup->counted_days,
            'round_tasks' => $cup->round_tasks,
            'round_minutes' => max(1, (int) config('cup.round.minutes', 10)),
            'timezone' => $cup->timezone,
            'starts_at' => $cup->starts_at === null ? null : IsoTime::format($cup->starts_at),
            'ends_at' => $cup->ends_at === null ? null : IsoTime::format($cup->ends_at),
            'day' => $day,
            'summaries_enabled' => $cup->summaries_enabled,
            'summary_time' => self::clock($cup->summary_minute),
            'paused' => CupAccess::pauseReason($cup->telegramGroup)?->value,
        ];
    }

    /**
     * The rules shown before joining and on the screen of the round: one attempt a day, 2 points for a clean right answer (a typo is 0),
     * the best `counted_days` days count.
     *
     * @return array{points_per_answer: int, typo_points: int, max_day_points: int, counted_days: int, days: int, max_total: int}
     */
    public static function rules(Cup $cup): array
    {
        $day = 2 * $cup->round_tasks;

        return [
            'points_per_answer' => 2,
            'typo_points' => 0,
            'max_day_points' => $day,
            'counted_days' => $cup->counted_days,
            'days' => $cup->days,
            'max_total' => $day * $cup->counted_days,
        ];
    }

    public static function clock(int $minute): string
    {
        $minute = max(0, min(1439, $minute));

        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }
}
