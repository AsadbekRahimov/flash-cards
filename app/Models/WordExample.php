<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Content\Services\WordExamples;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An additional example sentence of a word (docs/06 §12.4): `words.example` is position 0 (the anchor), these rows
 * are positions 1.. and rotate once the card has graduated. Read on the card through {@see WordExamples}
 * (cached); a writer that bypasses the model must drop the cache itself.
 *
 * @property int $id
 * @property int $word_id
 * @property int $position
 * @property string $text
 * @property array<string, string>|null $translations locale => translation of the sentence
 */
class WordExample extends Model
{
    /** @var list<string> */
    protected $fillable = ['word_id', 'position', 'text', 'translations'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'translations' => 'array',
        ];
    }

    /** @return BelongsTo<Word, $this> */
    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class);
    }
}
