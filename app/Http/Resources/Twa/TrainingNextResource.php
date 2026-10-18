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
     *   card: array{word_id: int, word: string, translation: string, translation_fallback: bool, example: string|null, transcription: string|null, card_kind: string, serve_token: string|null}|null,
     *   progress: array{done: int, total: int}
     * }
     */
    public function toArray(Request $request): array
    {
        $word = $this->resource->word;
        $translation = $this->resource->translation;

        return [
            'card' => $word !== null ? [
                'word_id' => (int) $word->id,
                'word' => $word->word,
                'translation' => $translation->text ?? $word->translation,
                // true: the text is the ru fallback because the session's language has no translation yet.
                'translation_fallback' => $translation->isFallback ?? false,
                'example' => $word->example,
                'transcription' => $word->transcription,
                'card_kind' => (string) $this->resource->kind,
                // Learn-ahead ticket: must be echoed as `serve_token` in POST /review (docs/06 §5.2).
                'serve_token' => $this->resource->serveToken,
            ] : null,
            'progress' => [
                'done' => $this->resource->progress['done'],
                'total' => $this->resource->progress['total'],
            ],
        ];
    }
}
