<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/twa/words/{word}/note — the learner's own memory hook (docs/06 §12.3). Access: `TwaAuth`; the word must be one
 * the learner has been served (WordNoteService). `null` or a blank clears the note; the text is plain (tags are stripped).
 */
final class WordNoteRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // A little above the stored length: the service trims and cuts; a wall of text is refused outright.
            'note' => ['present', 'nullable', 'string', 'max:'.(2 * max(1, (int) config('learning.hooks.note_max_length', 200)))],
        ];
    }

    public function note(): ?string
    {
        $note = $this->input('note');

        return is_string($note) ? $note : null;
    }
}
