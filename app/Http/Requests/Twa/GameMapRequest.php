<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use Illuminate\Foundation\Http\FormRequest;

/** GET /api/twa/game/map?course={slug} (docs/04 A.18). Access is the `TwaAuth` middleware and GameAccess. */
final class GameMapRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'course' => ['required', 'string', 'max:64'],
        ];
    }

    public function course(): string
    {
        return $this->string('course')->toString();
    }
}
