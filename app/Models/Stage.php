<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A module of a course. `title` is NULL when it has no name of its own: the reader is shown "Модуль N" / "N-modul"
 * in their language (docs/14 §8) - the TWA builds it from `number`, the admin panel from StageResource::displayTitle().
 *
 * @property string|null $title
 */
class Stage extends Model
{
    /** @use HasFactory<StageFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['course_id', 'number', 'title', 'description'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['number' => 'integer'];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return HasMany<Lesson, $this> */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }
}
