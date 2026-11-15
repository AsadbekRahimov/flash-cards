<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use Illuminate\Http\Request;

/**
 * PUT /api/twa/words/{word}/note (docs/06 §12.3): the note as stored (cleaned, cut), or null when cleared.
 *
 * @property array{word_id: int, note: string|null} $resource
 */
final class WordNoteResource extends TwaResource
{
    /**
     * @return array{word_id: int, note: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'word_id' => $this->resource['word_id'],
            'note' => $this->resource['note'],
        ];
    }
}
