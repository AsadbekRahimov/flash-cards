<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Services\Scoring\ScoreBoard;

/**
 * Which table {@see ScoreBoard} reads: a category of a v2 month (`league_month_scores`, index
 * `league_month_scores_category_standings_idx`) or a category of "all time" (`learner_score_totals`, index
 * `learner_score_totals_standings_idx`, S10). Both have the columns `learner_id, points, long_words, active_days, participant` and an
 * index in the exact order of the table after the equality prefix. A table of a region (docs/35 §2.4, R6) adds `region_code` to the
 * prefix and is read by the partial indexes `league_month_scores_region_standings_idx` / `learner_score_totals_region_standings_idx`
 * (`WHERE participant AND region_code IS NOT NULL`). Only these constructors exist: the table name and the columns never come from
 * input; the region is a value bound to the query (the callers pass only codes of `Regions`).
 */
final readonly class BoardScope
{
    /** @param  array<string, int|string>  $equals  column => value, the equality prefix of the index (before `participant`) */
    private function __construct(
        public string $table,
        public array $equals,
    ) {}

    /** A category of a v2 month; with `$region` - only the learners whose month is in that region (docs/35 §2.4). */
    public static function month(int $monthId, ScoreCategory $category, ?string $region = null): self
    {
        return new self('league_month_scores', [
            'league_month_id' => $monthId,
            'category' => $category->value,
            ...($region === null ? [] : ['region_code' => $region]),
        ]);
    }

    /** A category of "all time"; with `$region` - only the learners whose current region it is (docs/35 §2.4). */
    public static function allTime(ScoreCategory $category, ?string $region = null): self
    {
        return new self('learner_score_totals', [
            'category' => $category->value,
            ...($region === null ? [] : ['region_code' => $region]),
        ]);
    }

    /** The region of a region table, null for the whole category. */
    public function region(): ?string
    {
        $region = $this->equals['region_code'] ?? null;

        return is_string($region) ? $region : null;
    }
}
