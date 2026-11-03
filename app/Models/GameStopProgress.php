<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GameStopProgressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The best result of a learner on a stop (a lesson) of the map (docs/18 §12.2.3). Whether the stop is OPEN is not stored: it is derived from `word_repetitions`.
 *
 * @property int $id
 * @property int $learner_id
 * @property int $lesson_id
 * @property int $course_id
 * @property int $stage_id
 * @property int $stars
 * @property int $best_score
 * @property int $runs_count
 * @property CarbonImmutable|null $first_cleared_at
 * @property CarbonImmutable $last_run_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class GameStopProgress extends Model
{
    /** @use HasFactory<GameStopProgressFactory> */
    use HasFactory;

    protected $table = 'game_stop_progress';

    /** @var list<string> */
    protected $fillable = [
        'learner_id',
        'lesson_id',
        'course_id',
        'stage_id',
        'stars',
        'best_score',
        'runs_count',
        'first_cleared_at',
        'last_run_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'learner_id' => 'integer',
            'lesson_id' => 'integer',
            'course_id' => 'integer',
            'stage_id' => 'integer',
            'stars' => 'integer',
            'best_score' => 'integer',
            'runs_count' => 'integer',
            'first_cleared_at' => 'immutable_datetime',
            'last_run_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Stage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }
}
