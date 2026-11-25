<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa\Cup;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/twa/cup-rounds/{attempt}/answer (docs/04 A.23): `{request_id, element_id, answer}` - `answer` is `{option_id}`, `{text}` or
 * `{skip: true}`; the limits of the game (text up to 200 characters, ids up to 16). Which key a task needs is the core's to check (422
 * `invalid_answer_shape`). No time, no points, no verdict is read from the client.
 */
final class CupAnswerRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'string', 'uuid'],
            'element_id' => ['required', 'string', 'max:16'],
            'answer' => ['required', 'array:option_id,text,skip'],
            'answer.option_id' => ['nullable', 'string', 'max:16'],
            'answer.text' => ['nullable', 'string', 'max:200'],
            'answer.skip' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // A NUL byte is refused by PostgreSQL's jsonb, where the answer is stored.
        $text = $this->input('answer.text');
        if (is_string($text)) {
            $this->merge(['answer' => [...(array) $this->input('answer'), 'text' => str_replace("\0", '', $text)]]);
        }
    }

    /** A UUID in lower case (PostgreSQL stores it that way, SQLite compares strings). */
    public function requestId(): string
    {
        return strtolower($this->string('request_id')->toString());
    }

    public function elementId(): string
    {
        return $this->string('element_id')->toString();
    }

    /** @return array<string, mixed> */
    public function answer(): array
    {
        $answer = [];
        foreach ((array) $this->input('answer') as $key => $value) {
            if ($value !== null) {
                $answer[(string) $key] = $key === 'skip' ? filter_var($value, FILTER_VALIDATE_BOOL) : $value;
            }
        }

        return $answer;
    }
}
