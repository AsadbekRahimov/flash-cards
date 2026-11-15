<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Word extends Model
{
    /** @use HasFactory<WordFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'lesson_id',
        'word',
        'translation',
        'example',
        'part_of_speech',
        'transcription',
        'audio_path',
        'is_active',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return HasMany<WordTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(WordTranslation::class);
    }

    /** @return HasMany<WordExample, $this> */
    public function examples(): HasMany
    {
        return $this->hasMany(WordExample::class)->orderBy('position');
    }

    /**
     * The words (of the same course, by their English text) this word is mixed up with: `meta.confusable_with`
     * (docs/06 §12.5). Lower-cased, trimmed, without duplicates; empty when the content has no mark.
     *
     * @return list<string>
     */
    public function confusableWith(): array
    {
        $meta = $this->getAttribute('meta');
        $raw = is_array($meta) ? ($meta['confusable_with'] ?? null) : null;
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $value) {
            if (is_string($value) && trim($value) !== '') {
                $out[mb_strtolower(trim($value))] = true;
            }
        }

        return array_keys($out);
    }

    /** @return HasMany<WordRepetition, $this> */
    public function repetitions(): HasMany
    {
        return $this->hasMany(WordRepetition::class);
    }

    /** @return HasMany<TrainingReview, $this> */
    public function trainingReviews(): HasMany
    {
        return $this->hasMany(TrainingReview::class);
    }
}
