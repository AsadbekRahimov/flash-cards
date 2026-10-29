<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Practice\Enums\FinishReason;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\SessionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PracticeSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A solo practice session (docs/16 §2.5, §6): the whole issue is frozen in `snapshot` (server-only: it holds the
 * expected answers and never goes into a response), the progress in `state` (changed only under FOR UPDATE).
 * `active_learner_id` equals `learner_id` while the session is `active` and is NULL after: UNIQUE, so "one active
 * session per learner" is a database fact.
 *
 * @property int $id
 * @property int $learner_id
 * @property int|null $active_learner_id
 * @property PracticeMode $mode
 * @property int $course_id
 * @property int|null $lesson_id
 * @property string $source
 * @property int|null $retry_of_session_id
 * @property SessionStatus $status
 * @property FinishReason|null $finish_reason
 * @property array<string, mixed> $snapshot
 * @property array<string, mixed> $state
 * @property int $total
 * @property int $answered
 * @property int $correct_first
 * @property int $hints
 * @property int $mismatches
 * @property string $locale
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PracticeSession extends Model
{
    /** @use HasFactory<PracticeSessionFactory> */
    use HasFactory;

    /** Where the session was opened from (analytics): the hub, the end of a training session, "repeat the mistakes", a link. */
    public const SOURCE_HUB = 'hub';

    public const SOURCE_AFTER_TRAINING = 'after_training';

    public const SOURCE_RETRY = 'retry';

    public const SOURCE_DEEPLINK = 'deeplink';

    public const SOURCES = [self::SOURCE_HUB, self::SOURCE_AFTER_TRAINING, self::SOURCE_RETRY, self::SOURCE_DEEPLINK];

    /** @var list<string> */
    protected $fillable = [
        'learner_id',
        'active_learner_id',
        'mode',
        'course_id',
        'lesson_id',
        'source',
        'retry_of_session_id',
        'status',
        'finish_reason',
        'snapshot',
        'state',
        'total',
        'answered',
        'correct_first',
        'hints',
        'mismatches',
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
            'mode' => PracticeMode::class,
            'course_id' => 'integer',
            'lesson_id' => 'integer',
            'retry_of_session_id' => 'integer',
            'status' => SessionStatus::class,
            'finish_reason' => FinishReason::class,
            'snapshot' => 'array',
            'state' => 'array',
            'total' => 'integer',
            'answered' => 'integer',
            'correct_first' => 'integer',
            'hints' => 'integer',
            'mismatches' => 'integer',
            'expires_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === SessionStatus::Active;
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

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return BelongsTo<PracticeSession, $this> the session whose mistakes this one repeats */
    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_session_id');
    }

    /** @return HasMany<PracticeAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(PracticeAttempt::class, 'session_id');
    }
}
