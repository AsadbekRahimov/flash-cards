<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LeagueMonthScoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The materialised points of one learner in one month (docs/13 §7.6), written by `MonthlyRatingService` only — never in the
 * review transaction. `points` is the rating (the day points capped), `raw_points` the uncapped sum; the other counters are
 * the signals the administrator sees next to a candidate.
 *
 * @property int $id
 * @property int $league_month_id
 * @property int $learner_id
 * @property int $points
 * @property int $raw_points
 * @property int $active_days
 * @property int $capped_days
 * @property int $max_day_points
 * @property int $reviews
 * @property int $correct
 * @property int $new_words
 * @property int $goal_days
 * @property int $game_points
 * @property int $time_spent_ms
 * @property bool $participant
 * @property int|null $final_rank
 * @property string|null $category `free` / `pro` of a v2 month (docs/32 §2.6); NULL = a v1 month, one common table
 * @property int $long_words distinct lexemes with a credited long repetition in the month (v2 tie-break)
 * @property string|null $region_code the region of the learner's month (docs/35 §2.4): fixed at the first credit of the month, NULL = none / v1
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Learner|null $learner
 * @property-read LeagueMonth|null $month
 */
class LeagueMonthScore extends Model
{
    /** @use HasFactory<LeagueMonthScoreFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'league_month_id', 'learner_id', 'points', 'raw_points', 'active_days', 'capped_days', 'max_day_points',
        'reviews', 'correct', 'new_words', 'goal_days', 'game_points', 'time_spent_ms', 'participant', 'final_rank', 'category', 'long_words', 'region_code',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'league_month_id' => 'integer',
            'learner_id' => 'integer',
            'points' => 'integer',
            'raw_points' => 'integer',
            'active_days' => 'integer',
            'capped_days' => 'integer',
            'max_day_points' => 'integer',
            'reviews' => 'integer',
            'correct' => 'integer',
            'new_words' => 'integer',
            'goal_days' => 'integer',
            'game_points' => 'integer',
            'time_spent_ms' => 'integer',
            'participant' => 'boolean',
            'final_rank' => 'integer',
            'long_words' => 'integer',
        ];
    }

    /** @return BelongsTo<LeagueMonth, $this> */
    public function month(): BelongsTo
    {
        return $this->belongsTo(LeagueMonth::class, 'league_month_id');
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
