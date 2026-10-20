<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/twa/friends/{friendship}/…: only the shape of the id is checked here; whose it is, the service decides. */
final class FriendshipActionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['friendship' => $this->route('friendship')]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'friendship' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
        ];
    }

    public function friendshipId(): int
    {
        return $this->integer('friendship');
    }
}
