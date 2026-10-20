<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\AssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A lesson set by a center for one of its Telegram groups (docs/13 §1.4). Written by
 * AssignmentService only. A passed `due_at` does not close the assignment: it stays `active`
 * (late completions are still tracked) until closed by hand or by `assignments:refresh` after the
 * grace period — `isOverdue()` is the derived flag.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $telegram_group_id
 * @property int $lesson_id
 * @property string|null $title
 * @property Carbon $due_at
 * @property Carbon|null $announced_at
 * @property Carbon|null $reminded_at
 * @property string $status
 * @property int $completion_threshold
 * @property int|null $created_by_user_id
 * @property array<string, mixed> $meta
 * @property Carbon|null $created_at
 * @property-read Organization $organization all three parents are NOT NULL FKs with cascade / restrict
 * @property-read TelegramGroup $group
 * @property-read Lesson $lesson
 * @property-read int|null $total_count with scopeWithProgressCounts()
 * @property-read int|null $done_count with scopeWithProgressCounts()
 */
class Assignment extends Model
{
    /** @use HasFactory<AssignmentFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'telegram_group_id',
        'lesson_id',
        'title',
        'due_at',
        'announced_at',
        'reminded_at',
        'status',
        'completion_threshold',
        'created_by_user_id',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'telegram_group_id' => 'integer',
            'lesson_id' => 'integer',
            'due_at' => 'datetime',
            'announced_at' => 'datetime',
            'reminded_at' => 'datetime',
            'completion_threshold' => 'integer',
            'created_by_user_id' => 'integer',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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

    /** @return HasMany<AssignmentResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(AssignmentResult::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Active and the due date has passed. */
    public function isOverdue(?CarbonInterface $now = null): bool
    {
        return $this->isActive() && $this->due_at->lte($now ?? now());
    }

    /** The title shown to people: the custom one, the lesson's or "Lesson N". Needs `lesson` loaded. */
    public function displayTitle(?string $locale = null): string
    {
        return $this->title
            ?? $this->lesson->title
            ?? __('assignments.lesson_fallback', ['number' => $this->lesson->number], $locale);
    }

    /**
     * `total_count` / `done_count`: result rows of the learners who are STILL active in the group
     * (a learner who left keeps a snapshot, but is not counted as someone who "should do it").
     *
     * @param  Builder<Assignment>  $query
     */
    public function scopeWithProgressCounts(Builder $query): void
    {
        $activeInGroup = static fn ($sub) => $sub
            ->from('group_members')
            ->whereColumn('group_members.learner_id', 'assignment_results.learner_id')
            ->whereColumn('group_members.telegram_group_id', 'assignments.telegram_group_id')
            ->where('group_members.status', 'active');

        $query->withCount([
            'results as total_count' => fn (Builder $q) => $q->whereExists($activeInGroup),
            'results as done_count' => fn (Builder $q) => $q->whereNotNull('completed_at')->whereExists($activeInGroup),
        ]);
    }
}
