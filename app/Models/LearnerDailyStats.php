<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LearnerDailyStatsFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $learner_id
 * @property Carbon $date
 * @property int $reviews_count
 * @property int $new_words_count
 * @property int $correct_count
 * @property int $time_spent_ms
 * @property Carbon|null $goal_met_at
 * @property Carbon|null $frozen_at
 */
class LearnerDailyStats extends Model
{
    /** @use HasFactory<LearnerDailyStatsFactory> */
    use HasFactory;

    protected $table = 'learner_daily_stats';

    /** @var list<string> */
    protected $fillable = [
        'learner_id',
        'date',
        'reviews_count',
        'new_words_count',
        'correct_count',
        'time_spent_ms',
        'goal_met_at',
        'frozen_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'reviews_count' => 'integer',
            'new_words_count' => 'integer',
            'correct_count' => 'integer',
            'time_spent_ms' => 'integer',
            'goal_met_at' => 'datetime',
            'frozen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
