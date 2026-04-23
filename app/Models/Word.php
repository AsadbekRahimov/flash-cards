<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Word extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'lesson_id',
        'word',
        'translation',
        'example',
        'part_of_speech',
        'transcription',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return HasMany<WordRepetition, $this> */
    public function repetitions(): HasMany
    {
        return $this->hasMany(WordRepetition::class);
    }
}
