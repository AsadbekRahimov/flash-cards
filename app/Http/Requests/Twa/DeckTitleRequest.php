<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/twa/decks and PATCH /api/twa/decks/{slug}. Access: `TwaAuth`; ownership and limits are service rules. */
final class DeckTitleRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.(int) config('decks.title_max_length', 60)],
        ];
    }
}
