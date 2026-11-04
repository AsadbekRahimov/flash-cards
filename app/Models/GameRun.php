<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Game\Enums\FreeSlot;
use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Enums\RunKind;
use App\Domain\Game\Enums\RunStatus;
use Carbon\CarbonImmutable;
use Database\Factories\GameRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A run of the game "Caravan" (docs/18 §12.2.1, §12.3): the whole issue is frozen in `snapshot` (server-only: it holds the expected answers and never goes into a response), the progress in `state` (changed only under FOR UPDATE). `active_learner_id` equals `learner_id` while the run is `active` and is NULL after: UNIQUE, so "one active run per learner" is a database fact; `daily_key` and `class_boss_key` do the same for the daily caravan and the boss of a class.
 *
 * @property int $id
 * @property int $learner_id
 * @property int|null $active_learner_id
 * @property RunKind $kind
 * @property int $course_id
 * @property int|null $stage_id
 * @property int|null $lesson_id
 * @property int|null $class_caravan_id
 * @property string|null $daily_key
 * @property string|null $class_boss_key
 * @property CarbonImmutable $local_date
 * @property RunStatus $status
 * @property RunFinishReason|null $finish_reason
 * @property FreeSlot|null $free_slot
 * @property array<string, mixed> $snapshot
 * @property array<string, mixed> $state
 * @property int $total
 * @property int $answered
 * @property int $correct
 * @property int $typos
 * @property int $wrong
 * @property int $hints
 * @property int $flasks
 * @property int $flasks_max
 * @property int $combo_best
 * @property int $score
 * @property int|null $stars
 * @property int $coins_awarded
 * @property int $league_points
 * @property int $srs_pulled
 * @property string $locale
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class GameRun extends Model
{
    /** @use HasFactory<GameRunFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'learner_id',
        'active_learner_id',
        'kind',
        'course_id',
        'stage_id',
        'lesson_id',
        'class_caravan_id',
        'daily_key',
        'class_boss_key',
        'local_date',
        'status',
        'finish_reason',
        'free_slot',
        'snapshot',
        'state',
        'total',
        'answered',
        'correct',
        'typos',
        'wrong',
        'hints',
        'flasks',
        'flasks_max',
        'combo_best',
        'score',
        'stars',
        'coins_awarded',
        'league_points',
        'srs_pulled',
        'locale',
        'expires_at',
        'started_at',
        'finished_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'learner_id' => 'integer',
            'active_learner_id' => 'integer',
            'kind' => RunKind::class,
            'course_id' => 'integer',
            'stage_id' => 'integer',
            'lesson_id' => 'integer',
            'class_caravan_id' => 'integer',
            'local_date' => 'immutable_date',
            'status' => RunStatus::class,
            'finish_reason' => RunFinishReason::class,
            'free_slot' => FreeSlot::class,
            'snapshot' => 'array',
            'state' => 'array',
            'total' => 'integer',
            'answered' => 'integer',
            'correct' => 'integer',
            'typos' => 'integer',
            'wrong' => 'integer',
            'hints' => 'integer',
            'flasks' => 'integer',
            'flasks_max' => 'integer',
            'combo_best' => 'integer',
            'score' => 'integer',
            'stars' => 'integer',
            'coins_awarded' => 'integer',
            'league_points' => 'integer',
            'srs_pulled' => 'integer',
            'expires_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === RunStatus::Active;
    }

    /** Active, yet past `expires_at`: the scheduler has not closed it so far. */
    public function isExpiredAt(CarbonImmutable $now): bool
    {
        return $this->isActive() && $this->expires_at->lte($now);
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
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

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return BelongsTo<ClassCaravan, $this> */
    public function classCaravan(): BelongsTo
    {
        return $this->belongsTo(ClassCaravan::class, 'class_caravan_id');
    }

    /** @return HasMany<GameRunAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(GameRunAttempt::class, 'run_id');
    }
}
