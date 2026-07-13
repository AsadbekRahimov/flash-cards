<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ExamSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $telegram_group_id
 * @property int|null $lesson_id
 * @property int|null $started_by_user_id
 * @property string $status
 * @property Carbon|null $started_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $leaderboard_posted_at
 * @property array<string, mixed> $config
 */
class ExamSession extends Model
{
    /** @use HasFactory<ExamSessionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'telegram_group_id',
        'lesson_id',
        'started_by_user_id',
        'status',
        'started_at',
        'ends_at',
        'closed_at',
        'leaderboard_posted_at',
        'config',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
            'closed_at' => 'datetime',
            'leaderboard_posted_at' => 'datetime',
            'config' => 'array',
        ];
    }

    /** @return BelongsTo<TelegramGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TelegramGroup::class, 'telegram_group_id');
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return BelongsTo<User, $this> */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    /** @return HasMany<ExamAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(ExamAnswer::class);
    }

    /** @return HasMany<ExamResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(ExamResult::class);
    }

    /** @return HasMany<ExamQuestionAttempt, $this> */
    public function questionAttempts(): HasMany
    {
        return $this->hasMany(ExamQuestionAttempt::class);
    }
}
