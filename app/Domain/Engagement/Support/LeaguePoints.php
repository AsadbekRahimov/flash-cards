<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Support;

use InvalidArgumentException;

/**
 * THE points formula of the leagues (docs/13 §2.4, §7.3, §7.6) over one `learner_daily_stats` row:
 *
 *     reviews_count × review + correct_count × correct + new_words_count × new_word
 *     + (goal_met_at IS NOT NULL ? goal_met : 0) + game_points
 *
 * with the weights of `config('leagues.points')`; `game_points` is added as stored (the game capped it per day, docs/18 §12.6).
 * The weekly standings (`LeagueService`), the monthly rating (`MonthlyRatingService`) and the "+N points" of a finished
 * session (`SessionSummaryResource`) all take it from here, so one number means one thing everywhere.
 */
final class LeaguePoints
{
    /** @return array{review: int, correct: int, new_word: int, goal_met: int} */
    public static function weights(): array
    {
        return [
            'review' => (int) config('leagues.points.review', 1),
            'correct' => (int) config('leagues.points.correct', 1),
            'new_word' => (int) config('leagues.points.new_word', 2),
            'goal_met' => (int) config('leagues.points.goal_met', 10),
        ];
    }

    /**
     * The SQL of the points of ONE row of `learner_daily_stats` aliased `$alias`, with its bindings (the weights). Pure
     * arithmetic over the columns: the caller sums or caps it.
     *
     * @param  literal-string  $alias
     * @return array{0: literal-string, 1: list<int>}
     */
    public static function dayExpression(string $alias): array
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $alias) !== 1) {
            throw new InvalidArgumentException("Not a plain SQL alias: '{$alias}'.");
        }

        $w = self::weights();

        return [
            "({$alias}.reviews_count * ? + {$alias}.correct_count * ? + {$alias}.new_words_count * ? "
            ."+ case when {$alias}.goal_met_at is not null then ? else 0 end + {$alias}.game_points)",
            [$w['review'], $w['correct'], $w['new_word'], $w['goal_met']],
        ];
    }

    /** The same formula in PHP, for counts in hand (a finished session, a day row). */
    public static function ofCounts(int $reviews, int $correct, int $newWords, bool $goalMet, int $gamePoints = 0): int
    {
        $w = self::weights();

        return $reviews * $w['review'] + $correct * $w['correct'] + $newWords * $w['new_word'] + ($goalMet ? $w['goal_met'] : 0) + $gamePoints;
    }
}
