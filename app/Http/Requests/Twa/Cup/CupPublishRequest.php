<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa\Cup;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/twa/cups/setup/{code}/publish (docs/04 A.23): `{level?, locale?}` - the defaults of the draft otherwise. A level that is not
 * offered is the core's 422 `cup_level_invalid`; an unsupported language keeps the draft's.
 */
final class CupPublishRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'level' => ['nullable', 'string', 'max:2'],
            'locale' => ['nullable', 'string', 'max:5'],
        ];
    }

    public function level(): ?string
    {
        return $this->filled('level') ? $this->string('level')->toString() : null;
    }

    public function locale(): ?string
    {
        return $this->filled('locale') ? $this->string('locale')->toString() : null;
    }
}
