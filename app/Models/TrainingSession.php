<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\TrainingSessionFactory;
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
 * @property string $source
 * @property int|null $course_id
 * @property string $status
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $expires_at
 * @property string|null $done_reason
 */
class TrainingSession extends Model
{
    /** @use HasFactory<TrainingSessionFactory> */
    use HasFactory;

    public const MODE_GROUP_LESSON = 'group_lesson';

    public const MODE_LESSON = 'lesson';

    public const MODE_DAILY_REVIEW = 'daily_review';

    /** `done_reason`: closed explicitly by the learner (`/finish`). */
    public const DONE_FINISHED = 'finished';

    /** `done_reason`: closed by the scheduler (`expires_at` / group TTL) or superseded on re-open. */
    public const DONE_EXPIRED = 'expired';

    /** `done_reason`: a group session withdrawn because its Mini App button could not be delivered. */
    public const DONE_CANCELLED = 'cancelled';

    /** @var list<string> */
    protected $fillable = [
        'telegram_group_id',
        'lesson_id',
        'started_by_user_id',
        'learner_id',
        'mode',
        'source',
        'course_id',
        'status',
        'started_at',
        'ended_at',
        'expires_at',
        'done_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Open and, for a personal session, not past `expires_at` — the scheduler may not have run yet.
     * Group sessions have no `expires_at` (their age is judged on open, see TrainingSessionService).
     */
    public function isLiveAt(CarbonInterface $now): bool
    {
        if ($this->status !== 'open') {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isAfter($now);
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

    /** @return HasMany<TrainingReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(TrainingReview::class);
    }
}
