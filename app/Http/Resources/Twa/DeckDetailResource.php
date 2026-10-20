<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Content\DTO\CourseListing;
use App\Models\Word;
use App\Models\WordTranslation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * GET /api/twa/decks/{slug}: the deck plus its active words (the `translations` relation is loaded).
 *
 * @property array{deck: CourseListing, words: Collection<int, Word>} $resource
 */
final class DeckDetailResource extends TwaResource
{
    /**
     * @return array{
     *   slug: string,
     *   title: string,
     *   words_count: int,
     *   is_active: bool,
     *   progress: array{done: int, total: int},
     *   words: list<array{id: int, word: string, translation: string, translation_uz: string|null, example: string|null}>
     * }
     */
    public function toArray(Request $request): array
    {
        $words = [];
        foreach ($this->resource['words'] as $word) {
            $uz = $word->translations->first(fn (WordTranslation $row): bool => $row->locale === 'uz');

            $words[] = [
                'id' => $word->id,
                'word' => $word->word,
                'translation' => $word->translation,
                'translation_uz' => $uz?->translation,
                'example' => $word->example,
            ];
        }

        return (new DeckResource($this->resource['deck']))->toArray($request) + ['words' => $words];
    }
}
