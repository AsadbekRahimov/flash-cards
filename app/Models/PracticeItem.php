<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Practice\Enums\PracticeItemStatus;
use App\Domain\Practice\Enums\PracticeItemType;
use Carbon\CarbonImmutable;
use Database\Factories\PracticeItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An authored task of the practice (docs/16 §2.2): the stable `key`, the type and the pointers to its versions. The
 * text lives in the append-only {@see PracticeItemVersion}s; `status` is a projection of them, written only by
 * PracticeItemService. The learners get `published_version_id`, never `current_version_id`.
 *
 * @property int $id
 * @property int $course_id
 * @property int|null $lesson_id
 * @property PracticeItemType $type
 * @property string $key
 * @property string|null $level
 * @property PracticeItemStatus $status
 * @property int|null $current_version_id
 * @property int|null $published_version_id
 * @property int|null $created_by_user_id
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PracticeItem extends Model
{
    /** @use HasFactory<PracticeItemFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'course_id',
        'lesson_id',
        'type',
        'key',
        'level',
        'status',
        'current_version_id',
        'published_version_id',
        'created_by_user_id',
        'archived_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'lesson_id' => 'integer',
            'type' => PracticeItemType::class,
            'status' => PracticeItemStatus::class,
            'current_version_id' => 'integer',
            'published_version_id' => 'integer',
            'created_by_user_id' => 'integer',
            'archived_at' => 'immutable_datetime',
        ];
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

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<PracticeItemVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PracticeItemVersion::class, 'item_id');
    }

    /** @return BelongsTo<PracticeItemVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(PracticeItemVersion::class, 'current_version_id');
    }

    /** @return BelongsTo<PracticeItemVersion, $this> */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(PracticeItemVersion::class, 'published_version_id');
    }

    /**
     * The target words of the task (docs/16 §2.4).
     *
     * @return BelongsToMany<Word, $this>
     */
    public function words(): BelongsToMany
    {
        return $this->belongsToMany(Word::class, 'practice_item_words', 'item_id', 'word_id');
    }
}
