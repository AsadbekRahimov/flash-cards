<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Support\LadderPolicy;
use App\Models\Learner;
use App\Models\WordRepetition;
use Illuminate\Support\Facades\DB;

/**
 * The learner's own memory hook for a word — "my hint" (`word_repetitions.note`, docs/25 §3.4, docs/06 §12.3).
 *
 * Plain text only: tags are stripped, control characters dropped, whitespace collapsed, the length cut to
 * `learning.hooks.note_max_length` characters; a blank clears the note. Written with ONE conditional UPDATE on the
 * learner's own row of the word — no lock on `learners` (the picker's lock order, docs/06 §4.2) and nothing for a word the
 * learner has never been served (`null`: the controller answers 404 `card_not_started`). The note rides in the repetition
 * row, so the card reads it with no extra statement.
 */
final class WordNoteService
{
    public function __construct(private readonly LadderPolicy $policy) {}

    /**
     * @return array{word_id: int, note: string|null}|null null when the learner has no repetition row for the word
     */
    public function save(Learner $learner, int $wordId, ?string $note): ?array
    {
        $clean = $this->clean($note);

        $updated = DB::table('word_repetitions')
            ->where('learner_id', $learner->id)
            ->where('word_id', $wordId)
            ->update(['note' => $clean, 'updated_at' => now()]);

        if ($updated === 0) {
            return WordRepetition::query()->where('learner_id', $learner->id)->where('word_id', $wordId)->exists()
                ? ['word_id' => $wordId, 'note' => $clean]
                : null;
        }

        return ['word_id' => $wordId, 'note' => $clean];
    }

    public function clean(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $text = strip_tags($note);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, $this->policy->noteMaxLength);
    }
}
