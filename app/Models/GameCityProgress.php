<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GameCityProgressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The progress of a learner in a city (a module) of the map: the boss (docs/18 §12.2.3).
 *
 * @property int $id
 * @property int $learner_id
 * @property int $stage_id
 * @property int $course_id
 * @property int $boss_attempts
 * @property CarbonImmutable|null $boss_defeated_at
 * @property int $boss_best_accuracy
 * @property int $boss_wins
 * @property CarbonImmutable|null $shared_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class GameCityProgress extends Model
{
    /** @use HasFactory<GameCityProgressFactory> */
    use HasFactory;

    protected $table = 'game_city_progress';

    /** @var list<string> */
    protected $fillable = [
        'learner_id',
        'stage_id',
        'course_id',
        'boss_attempts',
        'boss_defeated_at',
        'boss_best_accuracy',
        'boss_wins',
        'shared_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'learner_id' => 'integer',
            'stage_id' => 'integer',
            'course_id' => 'integer',
            'boss_attempts' => 'integer',
            'boss_defeated_at' => 'immutable_datetime',
            'boss_best_accuracy' => 'integer',
            'boss_wins' => 'integer',
            'shared_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /** @return BelongsTo<Stage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
