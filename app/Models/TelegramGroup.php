<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TelegramGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $chat_id
 * @property string $title
 * @property string $status
 * @property int|null $organization_id
 * @property int|null $course_id
 * @property array<string, mixed> $meta
 */
class TelegramGroup extends Model
{
    /** @use HasFactory<TelegramGroupFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['chat_id', 'title', 'status', 'organization_id', 'course_id', 'meta'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'meta' => 'array',
        ];
    }

    /** @return BelongsToMany<User, $this> */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'teacher_groups')
            ->withPivot('is_primary')
            ->withTimestamps();
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

    /** @return HasMany<GroupMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    /** @return BelongsToMany<Learner, $this> */
    public function learners(): BelongsToMany
    {
        return $this->belongsToMany(Learner::class, 'group_members')
            ->withPivot(['status', 'joined_at', 'left_at'])
            ->withTimestamps();
    }

    /** @return HasMany<TrainingSession, $this> */
    public function trainingSessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }

    /** @return HasMany<ExamSession, $this> */
    public function examSessions(): HasMany
    {
        return $this->hasMany(ExamSession::class);
    }
}
