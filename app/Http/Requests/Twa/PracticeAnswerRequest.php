<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/twa/practice/sessions/{id}/answer (docs/16 §7.2, §12). The limits are the ones of §12 - `text` up to 200
 * characters, `order` up to 8 ids of 16 characters, a body up to 4 KB; the SHAPE of the answer for the mode (which of the
 * keys, are all the fragments there) is the mode's to check: 422 `invalid_answer_shape`.
 */
final class PracticeAnswerRequest extends FormRequest
{
    private const MAX_BODY_BYTES = 4096;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'string', 'uuid'],
            'element_id' => ['required', 'string', 'max:16'],
            'time_spent_ms' => ['nullable', 'integer', 'min:0', 'max:600000'],
            'answer' => ['required', 'array', function (string $attribute, mixed $value, Closure $fail): void {
                if (strlen((string) json_encode($value)) > self::MAX_BODY_BYTES) {
                    $fail(__('validation.max.string', ['attribute' => $attribute, 'max' => self::MAX_BODY_BYTES]));
                }
            }],
            'answer.text' => ['nullable', 'string', 'max:200'],
            'answer.skip' => ['nullable', 'boolean'],
            'answer.option_id' => ['nullable', 'string', 'max:16'],
            'answer.order' => ['nullable', 'array', 'max:8'],
            'answer.order.*' => ['string', 'max:16'],
            'answer.left_id' => ['nullable', 'string', 'max:16'],
            'answer.right_id' => ['nullable', 'string', 'max:16'],
            'answer.pairs' => ['nullable', 'array', 'max:4'],
            'answer.pairs.*' => ['string', 'max:16'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // A NUL byte is refused by PostgreSQL's jsonb, where the answer is stored.
        $answer = $this->input('answer');
        if (is_array($answer)) {
            $this->merge(['answer' => self::withoutNul($answer)]);
        }
    }

    /** The key of the request: a UUID in lower case (PostgreSQL stores it that way, SQLite compares strings). */
    public function requestId(): string
    {
        return strtolower($this->string('request_id')->toString());
    }

    public function elementId(): string
    {
        return $this->string('element_id')->toString();
    }

    public function timeSpentMs(): ?int
    {
        return $this->filled('time_spent_ms') ? $this->integer('time_spent_ms') : null;
    }

    /** @return array<string, mixed> */
    public function answer(): array
    {
        $answer = $this->input('answer');

        /** @var array<string, mixed> */
        return is_array($answer) ? $answer : [];
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function withoutNul(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $value[$key] = str_replace("\0", '', $item);
            } elseif (is_array($item)) {
                $value[$key] = self::withoutNul($item);
            }
        }

        return $value;
    }
}
