<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learning\DTO\ExercisePrompt;
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
     *   card: array{word_id: int, word: string, translation: string, translation_fallback: bool, example: string|null, transcription: string|null, audio_url: string|null, card_kind: string, serve_token: string|null, prompt: array{type: string, front: string, masked_example: string|null, options: list<string>|null, answer_length: int|null}|null}|null,
     *   progress: array{done: int, total: int},
     *   done_reason: string|null,
     *   quota: array{new_words_remaining: int|null}
     * }
     */
    public function toArray(Request $request): array
    {
        $word = $this->resource->word;
        $translation = $this->resource->translation;
        $prompt = $this->resource->prompt ?? ($word === null ? null : ExercisePrompt::flash($word->word, $this->resource->audioUrl));
        // Spelling / gap-fill: the typed word is the answer — the card must not carry it (nor its example, transcription
        // or audio). The right answer comes back in `graded.expected` of POST /review (docs/04 A.3).
        $hidden = $prompt !== null && $prompt->type->serverGraded();

        return [
            'card' => $word !== null ? [
                'word_id' => (int) $word->id,
                'word' => $hidden ? '' : $word->word,
                'translation' => $translation->text ?? $word->translation,
                // true: the text is the ru fallback because the session's language has no translation yet.
                'translation_fallback' => $translation->isFallback ?? false,
                'example' => $hidden ? null : $word->example,
                'transcription' => $hidden ? null : $word->transcription,
                // Pre-generated mp3 (task 1.2c); null -> the client falls back to Web Speech / GET /tts.
                'audio_url' => $hidden ? null : $this->resource->audioUrl,
                'card_kind' => (string) $this->resource->kind,
                // Learn-ahead ticket: must be echoed as `serve_token` in POST /review (docs/06 §5.2).
                'serve_token' => $this->resource->serveToken,
                // The exercise of this serve (docs/13 §2.5, docs/04 A.3): `type` decides the screen and
                // whether POST /review takes `quality` (flash / reverse / audio) or `answer` (spelling / gap_fill).
                'prompt' => $prompt === null ? null : [
                    'type' => $prompt->type->value,
                    'front' => $prompt->front,
                    'masked_example' => $prompt->maskedExample,
                    'options' => $prompt->options,
                    'answer_length' => $prompt->answerLength,
                ],
            ] : null,
            'progress' => [
                'done' => $this->resource->progress['done'],
                'total' => $this->resource->progress['total'],
            ],
            // Why `card` is null (docs/04 A.3); null while there is a card.
            'done_reason' => $this->resource->doneReason?->value,
            // New cards left today after this step (Free plan); null = unlimited (docs/12 §5.3).
            'quota' => ['new_words_remaining' => $this->resource->newWordsRemaining],
        ];
    }
}
