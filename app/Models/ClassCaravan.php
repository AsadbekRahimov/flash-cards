<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Game\Enums\ClassCaravanStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ClassCaravanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The caravan of a class for a week (docs/18 §12.2.8, G2.9). `active_group_id` equals `telegram_group_id` while the caravan is `active`: UNIQUE, so "one active caravan per group" is a database fact. `summary_posted_at` is the "exactly once" marker of the weekly summary.
 *
 * @property int $id
 * @property int $telegram_group_id
 * @property int|null $organization_id
 * @property int $course_id
 * @property int|null $started_by_user_id
 * @property int|null $active_group_id
 * @property ClassCaravanStatus $status
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property int $target_stars
 * @property int $stars_total
 * @property int $runs_total
 * @property list<int>|null $boss_snapshot_words
 * @property CarbonImmutable|null $boss_unlocked_at
 * @property CarbonImmutable|null $boss_defeated_at
 * @property CarbonImmutable|null $summary_posted_at
 * @property CarbonImmutable|null $finished_at
 * @property array<string, mixed> $meta
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ClassCaravan extends Model
{
    /** @use HasFactory<ClassCaravanFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'telegram_group_id',
        'organization_id',
        'course_id',
        'started_by_user_id',
        'active_group_id',
        'status',
        'starts_at',
        'ends_at',
        'target_stars',
        'stars_total',
        'runs_total',
        'boss_snapshot_words',
        'boss_unlocked_at',
        'boss_defeated_at',
        'summary_posted_at',
        'finished_at',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'telegram_group_id' => 'integer',
            'organization_id' => 'integer',
            'course_id' => 'integer',
            'started_by_user_id' => 'integer',
            'active_group_id' => 'integer',
            'status' => ClassCaravanStatus::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'target_stars' => 'integer',
            'stars_total' => 'integer',
            'runs_total' => 'integer',
            'boss_snapshot_words' => 'array',
            'boss_unlocked_at' => 'immutable_datetime',
            'boss_defeated_at' => 'immutable_datetime',
            'summary_posted_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'meta' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === ClassCaravanStatus::Active;
    }

    /** @return BelongsTo<TelegramGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TelegramGroup::class, 'telegram_group_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<User, $this> */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    /** @return HasMany<ClassCaravanMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ClassCaravanMember::class, 'class_caravan_id');
    }

    /** @return HasMany<GameRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(GameRun::class, 'class_caravan_id');
    }
}
