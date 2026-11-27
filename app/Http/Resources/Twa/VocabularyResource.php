<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learning\DTO\VocabularyPage;
use Illuminate\Http\Request;

/**
 * GET /api/twa/vocabulary (docs/04 A.25): `total` and `counts` on the first page only (null after a cursor), the rows, the cursor of
 * the next page (null - the last page).
 *
 * @property VocabularyPage $resource
 */
final class VocabularyResource extends TwaResource
{
    /**
     * @return array{
     *   total: int|null,
     *   counts: array{all: int, weak: int, strong: int}|null,
     *   items: list<array{word_id: int, word: string, translation: string, translation_fallback: bool, transcription: string|null, audio_url: string|null, strength: int, stage: string, next_in_days: int|null, is_hard: bool}>,
     *   next_cursor: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        $counts = $this->resource->counts;

        return [
            'total' => $counts['all'] ?? null,
            'counts' => $counts,
            'items' => $this->resource->items,
            'next_cursor' => $this->resource->nextCursor,
        ];
    }
}
