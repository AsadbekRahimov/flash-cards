<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\CourseTreeObserver;
use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A lesson of a module. `title` is its Russian name, `title_uz` the Uzbek one (docs/03 §2.6), both optional; a learner is
 * given the one of their locale by DeckTitles (`title_uz` for uz when it is set, else `title`).
 *
 * @property string|null $title
 * @property string|null $title_uz
 */
#[ObservedBy(CourseTreeObserver::class)]
class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['stage_id', 'number', 'title', 'title_uz'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['number' => 'integer'];
    }

    /** @return BelongsTo<Stage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    /** @return HasMany<Word, $this> */
    public function words(): HasMany
    {
        return $this->hasMany(Word::class);
    }
}
