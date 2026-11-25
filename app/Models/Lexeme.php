<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LexemeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A lexeme of the catalog (docs/32 §2.1): one normalised spelling (`App\Domain\Content\Support\LexemeKey`) of a language.
 * Written only by `App\Domain\Content\Services\LexemeCatalog` (insert-or-ignore, never updated).
 *
 * @property int $id
 * @property string $language
 * @property string $key
 * @property Carbon|null $created_at
 */
class Lexeme extends Model
{
    /** @use HasFactory<LexemeFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['language', 'key'];

    /** @return HasMany<Word, $this> */
    public function words(): HasMany
    {
        return $this->hasMany(Word::class);
    }
}
