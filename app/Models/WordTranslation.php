<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WordTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $word_id
 * @property string $locale
 * @property string $translation
 * @property string|null $example_translation
 * @property string $source
 * @property Carbon|null $reviewed_at
 */
class WordTranslation extends Model
{
    /** @use HasFactory<WordTranslationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'word_id',
        'locale',
        'translation',
        'example_translation',
        'source',
        'reviewed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    /** @return BelongsTo<Word, $this> */
    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class);
    }
}
