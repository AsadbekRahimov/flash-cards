<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/twa/challenges/{challenge}/…: only the shape of the id is checked here; whose it is, the service decides. */
final class ChallengeActionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['challenge' => $this->route('challenge')]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'challenge' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
        ];
    }

    public function challengeId(): int
    {
        return $this->integer('challenge');
    }
}
