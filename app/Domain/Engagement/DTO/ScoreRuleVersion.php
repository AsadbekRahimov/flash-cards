<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScoreKind;
use App\Domain\Engagement\Services\Scoring\ScoreRules;

/**
 * One published, immutable version of the word-points rules (`leagues.scoring.versions.N`, docs/32 §6.1), as read by
 * {@see ScoreRules}: the points of each kind, the interval that makes a repetition
 * "long", the best days of a week, the limits per category, and the first day of the periods it applies to (null = not
 * scheduled, it never applies).
 */
final readonly class ScoreRuleVersion
{
    /**
     * @param  array{new: int, review: int, long: int}  $points
     * @param  array{free: ScoreLimits, pro: ScoreLimits}  $limits
     */
    public function __construct(
        public int $version,
        public ?string $startsOn,
        public array $points,
        public int $longIntervalDays,
        public int $bestDays,
        public array $limits,
    ) {}

    public function pointsFor(ScoreKind $kind): int
    {
        return $this->points[$kind->value];
    }

    public function limitsFor(ScoreCategory $category): ScoreLimits
    {
        return $this->limits[$category->value];
    }
}
