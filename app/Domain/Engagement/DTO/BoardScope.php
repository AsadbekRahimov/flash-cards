<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Services\Scoring\ScoreBoard;

/**
 * Which table {@see ScoreBoard} reads: a category of a v2 month (`league_month_scores`, index
 * `league_month_scores_category_standings_idx`) or a category of "all time" (`learner_score_totals`, index
 * `learner_score_totals_standings_idx`, S10). Both have the columns `learner_id, points, long_words, active_days, participant` and an
 * index in the exact order of the table after the equality prefix. Only these constructors exist: the table name and the columns
 * never come from input.
 */
final readonly class BoardScope
{
    /** @param  array<string, int|string>  $equals  column => value, the equality prefix of the index (before `participant`) */
    private function __construct(
        public string $table,
        public array $equals,
    ) {}

    public static function month(int $monthId, ScoreCategory $category): self
    {
        return new self('league_month_scores', ['league_month_id' => $monthId, 'category' => $category->value]);
    }

    public static function allTime(ScoreCategory $category): self
    {
        return new self('learner_score_totals', ['category' => $category->value]);
    }
}
