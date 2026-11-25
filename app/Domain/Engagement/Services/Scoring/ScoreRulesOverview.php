<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services\Scoring;

use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Models\LeagueMonth;
use App\Models\LeagueWeek;
use Carbon\CarbonImmutable;

/**
 * The word-points rules as the administrator reads them (docs/32 §6.1, §8.5, S15): the mode, the switch of the game, the retention
 * of the journal, every published version with its weights and limits, and which version the current week and month count by. Read
 * only — the rules are edited in the code and the environment, never in /admin (a live period must not change its weights).
 *
 * The configuration is in memory; the periods are the rows of the current week and month (two reads by their unique `starts_on`).
 * A period without a row yet shows the version it would get if it were created now ({@see ScoreRules::versionFor()}).
 */
final class ScoreRulesOverview
{
    public function __construct(
        private readonly ScoreRules $rules,
        private readonly LeagueService $leagues,
        private readonly MonthlyRatingService $months,
    ) {}

    /**
     * @return array{
     *     mode: string,
     *     game_enabled: bool,
     *     retention_days: int,
     *     starts_on: string|null,
     *     versions: list<array{version: int, starts_on: string|null, points: array{new: int, review: int, long: int}, long_interval_days: int, best_days: int, limits: array<string, array{new: int, review: int, day_cap: int}>}>,
     *     week: array{starts_on: string, version: int, stored: bool},
     *     month: array{starts_on: string, version: int, stored: bool},
     * }
     */
    public function overview(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $versions = [];
        foreach ($this->rules->versions() as $version) {
            $limits = [];
            foreach ($version->limits as $category => $limit) {
                $limits[$category] = ['new' => $limit->new, 'review' => $limit->review, 'day_cap' => $limit->dayCap];
            }
            $versions[] = [
                'version' => $version->version,
                'starts_on' => $version->startsOn,
                'points' => $version->points,
                'long_interval_days' => $version->longIntervalDays,
                'best_days' => $version->bestDays,
                'limits' => $limits,
            ];
        }

        return [
            'mode' => $this->rules->mode()->value,
            'game_enabled' => $this->rules->gameEnabled(),
            'retention_days' => $this->rules->creditsRetentionDays(),
            'starts_on' => $this->rules->version(2)?->startsOn,
            'versions' => $versions,
            'week' => $this->period($this->leagues->findWeek($now), $this->leagues->weekStart($now)->toDateString()),
            'month' => $this->period($this->months->findMonth($now), $this->months->monthStart($now)->toDateString()),
        ];
    }

    /** @return array{starts_on: string, version: int, stored: bool} */
    private function period(LeagueWeek|LeagueMonth|null $row, string $startsOn): array
    {
        return $row === null
            ? ['starts_on' => $startsOn, 'version' => $this->rules->versionFor($startsOn), 'stored' => false]
            : ['starts_on' => $row->starts_on->toDateString(), 'version' => (int) $row->rules_version, 'stored' => true];
    }
}
