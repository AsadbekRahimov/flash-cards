<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Content\Support\Phrase;
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
     *   card: array{word_id: int, word: string, translation: string, translation_fallback: bool, example: string|null, example_translation: string|null, example_audio_url: string|null, transcription: string|null, audio_url: string|null, card_kind: string, serve_token: string|null, prompt: array{type: string, front: string, masked_example: string|null, options: list<string>|null, answer_length: int|null}|null, is_leech: bool, is_hard: bool, hook: string|null, note: string|null, confused_with: array{word: string, translation: string|null}|null, phrase: array{kind: string|null, register: string|null, variant: string, literal: string|null}|null, memory: array{strength: int, stage: string, next_in_days: int|null}|null}|null,
     *   progress: array{done: int, total: int},
     *   done_reason: string|null,
     *   can_continue: bool,
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
                // The translation of exactly that example, in the session's language: hidden with it (the translation of a gap-fill
                // sentence or of a reverse card's example would give the answer away), and back in `revealed` for meaning / reverse / audio.
                'example_translation' => $hidden ? null : $this->resource->exampleTranslation,
                // Pre-generated mp3 of exactly that example (docs/26 §9.11): hidden with it; null -> no button (no Web Speech).
                'example_audio_url' => $hidden ? null : $this->resource->exampleAudioUrl,
                'transcription' => $hidesWord || $reverse ? null : $word->transcription,
                // Pre-generated mp3 (task 1.2c); null -> the client falls back to Web Speech (docs/26 §9.9).
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
                // A phrase card (docs/35 §3.3 d, docs/06 §16): kind, register, UK / US always; the literal translation is hidden with
                // the example (it would give the answer of a choice or typed card away) and comes back in `revealed.phrase`. Null for a word.
                'phrase' => Phrase::forCard($word, $translation->locale ?? 'ru', ! $hidden),
                // "Memory strength" BEFORE this grade (docs/40 §2.2): 0–5 dots, the stage, whole days to the next review (null - in the
                // minute steps). Never gives an answer away, so not hidden.
                'memory' => $this->resource->memory,
            ] : null,
            'progress' => [
                'done' => $this->resource->progress['done'],
                'total' => $this->resource->progress['total'],
            ],
            // Why `card` is null (docs/04 A.3); null while there is a card.
            'done_reason' => $this->resource->doneReason?->value,
            // The round is over but another one would have cards (`sprint_complete`, docs/06 §13.2): "one more round".
            'can_continue' => $this->resource->canContinue,
            // New cards left today after this step (Free plan); null = unlimited (docs/12 §5.3).
            'quota' => ['new_words_remaining' => $this->resource->newWordsRemaining],
        ];
    }
}
