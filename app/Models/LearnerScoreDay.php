<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Engagement\Enums\ScoreCategory;
use Database\Factories\LearnerScoreDayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The word points of one learner on one rating day of the league zone (docs/32 §2.4): the raw facts without limits, and the
 * weekly and the monthly count, each under the category and rules version of its period - copied in when the row is created,
 * so neither the answer nor the tables read `learner_score_periods`. Kept forever: the source of "all time" and of corrections.
 * Unique `(learner_id, rating_day)`; written under `FOR UPDATE` by the scoring core only (S4).
 *
 * @property int $id
 * @property int $learner_id
 * @property Carbon $rating_day
 * @property Carbon $week_starts_on
 * @property ScoreCategory $week_category
 * @property int $week_version
 * @property Carbon $month_starts_on
 * @property ScoreCategory $month_category
 * @property int $month_version
 * @property int $raw_points
 * @property int $new_count
 * @property int $review_count
 * @property int $long_count
 * @property int $week_points
 * @property int $week_new
 * @property int $week_review
 * @property int $week_long
 * @property Carbon|null $week_capped_at
 * @property int $month_points
 * @property int $month_new
 * @property int $month_review
 * @property int $month_long
 * @property Carbon|null $month_capped_at
 * @property int $revision
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LearnerScoreDay extends Model
{
    /** @use HasFactory<LearnerScoreDayFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'learner_id', 'rating_day',
        'week_starts_on', 'week_category', 'week_version', 'month_starts_on', 'month_category', 'month_version',
        'raw_points', 'new_count', 'review_count', 'long_count',
        'week_points', 'week_new', 'week_review', 'week_long', 'week_capped_at',
        'month_points', 'month_new', 'month_review', 'month_long', 'month_capped_at',
        'revision',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'learner_id' => 'integer',
            'rating_day' => 'date',
            'week_starts_on' => 'date',
            'week_category' => ScoreCategory::class,
            'week_version' => 'integer',
            'month_starts_on' => 'date',
            'month_category' => ScoreCategory::class,
            'month_version' => 'integer',
            'raw_points' => 'integer',
            'new_count' => 'integer',
            'review_count' => 'integer',
            'long_count' => 'integer',
            'week_points' => 'integer',
            'week_new' => 'integer',
            'week_review' => 'integer',
            'week_long' => 'integer',
            'week_capped_at' => 'datetime',
            'month_points' => 'integer',
            'month_new' => 'integer',
            'month_review' => 'integer',
            'month_long' => 'integer',
            'month_capped_at' => 'datetime',
            'revision' => 'integer',
        ];
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
