<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\CourseTreeObserver;
use Database\Factories\StageFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A module of a course. `title` is its Russian name, `title_uz` the Uzbek one (docs/03 §2.5); what a learner reads is picked
 * by their locale in DeckTitles (`title_uz` for uz when it is set, else `title`). `title` is NULL when the module has no name of
 * its own: the reader is shown "Модуль N" / "N-modul" in their language (docs/14 §8) - the TWA builds it from `number`, the
 * admin panel from StageResource::displayTitle().
 *
 * @property string|null $title
 * @property string|null $title_uz
 */
#[ObservedBy(CourseTreeObserver::class)]
class Stage extends Model
{
    /** @use HasFactory<StageFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['course_id', 'number', 'title', 'title_uz', 'description'];

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
