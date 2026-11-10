<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Domain\Content\DTO\ResolvedTranslation;
use App\Domain\Learning\Enums\DoneReason;
use App\Models\Word;

/**
 * What the training screen should show next: a card (or nothing — "done", with
 * the reason) plus the session progress.
 */
final readonly class TrainingStep
{
    /**
     * @param  'due'|'new'|'learning'|null  $kind  null exactly when $word is null
     * @param  array{done:int, total:int}  $progress  lesson scope: graduated / active words of the lesson;
     *                                                daily scope: grades in this session / learner's daily_goal
     * @param  string|null  $serveToken  learn-ahead ticket the client must echo in POST /review
     *                                   (docs/06 §5.2); null for due/new cards and when $word is null
     * @param  ResolvedTranslation|null  $translation  the card's translation in the session's locale (with the ru
     *                                                 fallback flag); null exactly when $word is null
     * @param  DoneReason|null  $doneReason  why there is no card; set exactly when $word is null
     * @param  int|null  $newWordsRemaining  new cards the learner may still get today after this step
     *                                       (Free quota, docs/12 §5.3); null = unlimited
     * @param  ExercisePrompt|null  $prompt  the exercise the card is served with (docs/13 §2.5); null exactly when $word is null
     * @param  string|null  $audioUrl  pre-generated mp3 of the word (task 1.2c); null → the client falls back to /tts
     * @param  string|null  $example  the example this serve shows (docs/06 §12.4: the anchor, or a rotated one after graduation)
     * @param  bool  $isLeech  a "hard word" — `lapses >= learning.leech.threshold` (docs/06 §12.6)
     * @param  bool  $isHard  `word_repetitions.is_hard`: failed and not yet passed a day-based review (docs/06 §3.5)
     * @param  string|null  $hook  the authored memory hook in the card's language, when this serve shows one (docs/06 §12.3)
     * @param  string|null  $note  the learner's own hook for the word
     * @param  array{word: string, translation: string|null}|null  $confusedWith  the word a leech is mixed up with
     */
    public function __construct(
        public ?Word $word,
        public ?string $kind,
        public array $progress,
        public ?string $serveToken = null,
        public ?ResolvedTranslation $translation = null,
        public ?DoneReason $doneReason = null,
        public ?int $newWordsRemaining = null,
        public ?ExercisePrompt $prompt = null,
        public ?string $audioUrl = null,
        public ?string $example = null,
        public bool $isLeech = false,
        public bool $isHard = false,
        public ?string $hook = null,
        public ?string $note = null,
        public ?array $confusedWith = null,
    ) {}

    /**
     * @param  array{done:int, total:int}  $progress
     */
    public static function done(DoneReason $reason, array $progress, ?int $newWordsRemaining = null): self
    {
        return new self(null, null, $progress, doneReason: $reason, newWordsRemaining: $newWordsRemaining);
    }
}
