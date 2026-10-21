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
 * @property int|null $telegram_group_id
 * @property int|null $lesson_id
 * @property int|null $started_by_user_id
 * @property int|null $learner_id
 * @property string $mode
 * @property int|null $course_id
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

    /** A teacher's exam in a Telegram group (Phase 1). */
    public const MODE_GROUP_EXAM = 'group_exam';

    /** A learner's own timed mock of an IELTS / SAT course (docs/13 §8.4). */
    public const MODE_PERSONAL_MOCK = 'personal_mock';

    /** @var list<string> */
    protected $fillable = [
        'telegram_group_id',
        'lesson_id',
        'started_by_user_id',
        'learner_id',
        'mode',
        'course_id',
        'status',
        'started_at',
        'ends_at',
        'closed_at',
        'leaderboard_posted_at',
        'config',
    ];

    /**
     * `config` holds the answer key and the ordering secret: never serialize it.
     *
     * @var list<string>
     */
    protected $hidden = ['config'];

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

    /**
     * Owner of a `personal_mock` session; null for group exams.
     *
     * @return BelongsTo<Learner, $this>
     */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /**
     * Course of a `personal_mock` session; null for group exams.
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function isPersonalMock(): bool
    {
        return $this->mode === self::MODE_PERSONAL_MOCK;
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
