<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa\Cup;

use Illuminate\Foundation\Http\FormRequest;

/** PATCH /api/twa/cups/{id}/me (docs/04 A.23): the new name in the tournament; its rules are the core's (422 `cup_display_name_invalid`). */
final class CupRenameRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:256'],
        ];
    }

    public function displayName(): string
    {
        return $this->string('display_name')->toString();
    }
}
