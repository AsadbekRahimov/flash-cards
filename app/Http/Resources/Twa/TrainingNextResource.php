<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learning\DTO\ExercisePrompt;
use App\Domain\Learning\DTO\TrainingStep;
use App\Domain\Learning\Enums\ExerciseType;
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
     *   card: array{word_id: int, word: string, translation: string, translation_fallback: bool, example: string|null, transcription: string|null, audio_url: string|null, card_kind: string, serve_token: string|null, prompt: array{type: string, front: string, masked_example: string|null, options: list<string>|null, answer_length: int|null}|null, is_leech: bool, is_hard: bool, hook: string|null, note: string|null, confused_with: array{word: string, translation: string|null}|null}|null,
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
        // Spelling / gap-fill: the typed word is the answer — the card must not carry it (nor its example, transcription,
        // audio or the hooks). The right answer comes back in `graded.expected` of POST /review (docs/04 A.3).
        $hidesWord = $prompt !== null && $prompt->type->hidesWord();
        // Pretest: the meaning is the answer — the translation, the example and the hooks wait for the verdict (docs/06 §12.7).
        $hidesTranslation = $prompt !== null && $prompt->type->hidesTranslation();
        // Meaning / reverse / audio: the picked option is graded by the server, so the card carries nothing that gives the right
        // option away — reverse: the word is the answer (and its example, transcription, audio); meaning / audio: the translation
        // (and the example). All come back as `revealed` in POST /review (docs/06 §12.2). The hooks and the note may name either,
        // so they wait too.
        $hidesChoice = $prompt !== null && $prompt->type->hidesChoiceAnswer();
        $reverse = $prompt?->type === ExerciseType::Reverse;
        $hidden = $hidesWord || $hidesTranslation || $hidesChoice;

        return [
            'card' => $word !== null ? [
                'word_id' => (int) $word->id,
                'word' => $hidesWord || $reverse ? '' : $word->word,
                'translation' => $hidesTranslation || ($hidesChoice && ! $reverse) ? '' : ($translation->text ?? $word->translation),
                // true: the text is the ru fallback because the session's language has no translation yet.
                'translation_fallback' => $translation->isFallback ?? false,
                // The example of this serve (docs/06 §12.4): the anchor until graduation, then the rotation.
                'example' => $hidden ? null : $this->resource->example,
                'transcription' => $hidesWord || $reverse ? null : $word->transcription,
                // Pre-generated mp3 (task 1.2c); null -> the client falls back to Web Speech / GET /tts.
                'audio_url' => $hidesWord || $reverse ? null : $this->resource->audioUrl,
                'card_kind' => (string) $this->resource->kind,
                // Learn-ahead ticket: must be echoed as `serve_token` in POST /review (docs/06 §5.2).
                'serve_token' => $this->resource->serveToken,
                // The exercise of this serve (docs/13 §2.5, docs/04 A.3): `type` decides the screen and
                // whether POST /review takes `quality` (flash) or `answer` (the four options, spelling, gap_fill, pretest).
                'prompt' => $prompt === null ? null : [
                    'type' => $prompt->type->value,
                    'front' => $prompt->front,
                    'masked_example' => $prompt->maskedExample,
                    'options' => $prompt->options,
                    'answer_length' => $prompt->answerLength,
                ],
                // The method (docs/06 §12): a hard word (leech), a failed card, the memory hooks and the confusable pair.
                'is_leech' => $this->resource->isLeech,
                'is_hard' => $this->resource->isHard,
                'hook' => $hidden ? null : $this->resource->hook,
                'note' => $hidden ? null : $this->resource->note,
                'confused_with' => $hidden ? null : $this->resource->confusedWith,
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
