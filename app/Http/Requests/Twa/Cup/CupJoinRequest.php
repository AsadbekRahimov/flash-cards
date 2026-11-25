<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa\Cup;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/twa/cups/{id}/join (docs/04 A.23): `display_name` is optional (the Telegram first name otherwise). Only the size is checked
 * here; what is a valid name in a tournament (1-32 characters, no control or format characters) is the core's - 422
 * `cup_display_name_invalid`.
 */
final class CupJoinRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'display_name' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function displayName(): ?string
    {
        $name = $this->input('display_name');

        return is_string($name) ? $name : null;
    }
}
