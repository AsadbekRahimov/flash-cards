<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Models\GameRun;
use App\Models\Learner;
use Carbon\CarbonImmutable;

/**
 * The figures of the widget `GameActivityWidget` of `/admin` (docs/18 §12.10, §12.12): the last 7 days of the game, as scalars
 * (the widget reads them through `WidgetCache`, never as objects).
 */
final class GameActivityStats
{
    public const DAYS = 7;

    /** @return array{runs: int, players: int, active: int, share: int} `share` = whole percent of the active learners who played */
    public function lastWeek(?CarbonImmutable $now = null): array
    {
        $since = ($now ?? CarbonImmutable::now())->subDays(self::DAYS);

        $runs = GameRun::query()->where('started_at', '>=', $since)->count();
        $players = GameRun::query()->where('started_at', '>=', $since)->distinct()->count('learner_id');
        $active = Learner::query()->where('last_seen_at', '>=', $since)->count();

        return [
            'runs' => $runs,
            'players' => $players,
            'active' => $active,
            'share' => $active > 0 ? min(100, (int) round($players * 100 / $active)) : 0,
        ];
    }
}
