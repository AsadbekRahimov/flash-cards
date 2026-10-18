<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learning\DTO\TrainingStep;
use Illuminate\Http\Request;

/**
 * GET /api/twa/training/sessions/{id}/next — also embedded (flattened) into
 * the start and review responses.
 *
 * @property TrainingStep $resource
 */
final class TrainingNextResource extends TwaResource
{
    /**
     * @return array{
     *   card: array{word_id: int, word: string, translation: string, example: string|null, transcription: string|null, card_kind: string}|null,
     *   progress: array{done: int, total: int}
     * }
     */
    public function toArray(Request $request): array
    {
        $word = $this->resource->word;

        return [
            'card' => $word !== null ? [
                'word_id' => (int) $word->id,
                'word' => $word->word,
                'translation' => $word->translation,
                'example' => $word->example,
                'transcription' => $word->transcription,
                'card_kind' => (string) $this->resource->kind,
            ] : null,
            'progress' => [
                'done' => $this->resource->progress['done'],
                'total' => $this->resource->progress['total'],
            ],
        ];
    }
}
