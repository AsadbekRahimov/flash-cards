<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Content\DTO\CourseListing;
use App\Domain\Content\DTO\DeckLimits;
use Illuminate\Http\Request;

/**
 * GET /api/twa/decks: `{"decks": [DeckResource, ...], "limits": {decks, deck_words, used_decks}}`.
 *
 * @property array{decks: list<CourseListing>, limits: DeckLimits} $resource
 */
final class DeckListResource extends TwaResource
{
    /**
     * @return array{decks: list<array<string, mixed>>, limits: array{decks: int, deck_words: int, used_decks: int}}
     */
    public function toArray(Request $request): array
    {
        $limits = $this->resource['limits'];

        return [
            'decks' => array_map(
                fn (CourseListing $listing): array => (new DeckResource($listing))->toArray($request),
                $this->resource['decks'],
            ),
            'limits' => [
                'decks' => $limits->decks,
                'deck_words' => $limits->deckWords,
                'used_decks' => $limits->usedDecks,
            ],
        ];
    }
}
