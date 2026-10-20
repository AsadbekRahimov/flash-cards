<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Content\Services\DeckImportParser;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/twa/decks/{slug}/words. Access: `TwaAuth`; ownership, limits and the content of the text
 * (junk lines are dropped, not rejected) are service rules. The size cap is in BYTES.
 */
final class ImportDeckWordsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $maxKb = max(1, (int) config('decks.import.max_kb', 256));

        return [
            'text' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) use ($maxKb): void {
                    if (is_string($value) && strlen($value) > $maxKb * 1024) {
                        $fail(__('validation.max.file', ['attribute' => __('validation.attributes.text'), 'max' => $maxKb]));
                    }
                },
            ],
            'format' => ['required', 'string', Rule::in([DeckImportParser::FORMAT_LIST, DeckImportParser::FORMAT_CSV])],
        ];
    }
}
