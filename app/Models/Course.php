<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $owner_type
 * @property int|null $owner_id
 * @property array<string, string> $title
 * @property array<string, string> $description
 * @property string $target_language
 * @property string|null $level
 * @property string|null $exam_tag
 * @property string $visibility
 * @property string $status
 * @property Carbon|null $published_at
 * @property int $position
 * @property bool $is_featured
 * @property int $words_count
 * @property array<string, mixed> $meta
 */
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'slug',
        'owner_type',
        'owner_id',
        'title',
        'description',
        'target_language',
        'level',
        'exam_tag',
        'visibility',
        'status',
        'published_at',
        'position',
        'is_featured',
        'words_count',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'owner_id' => 'integer',
            'title' => 'array',
            'description' => 'array',
            'published_at' => 'datetime',
            'position' => 'integer',
            'is_featured' => 'boolean',
            'words_count' => 'integer',
            'meta' => 'array',
        ];
    }

    /**
     * Courses a learner may open and study: published and not private. The SQL half of the single
     * availability rule — the PHP half is CourseCatalogService::isAvailable(); change them together.
     *
     * @param  Builder<Course>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('courses.status', 'published')->where('courses.visibility', '!=', 'private');
    }

    /** @return HasMany<Stage, $this> */
    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class);
    }

    /** @return HasMany<TelegramGroup, $this> */
    public function telegramGroups(): HasMany
    {
        return $this->hasMany(TelegramGroup::class);
    }

    /** @return HasMany<Learner, $this> */
    public function learners(): HasMany
    {
        return $this->hasMany(Learner::class, 'active_course_id');
    }
}
