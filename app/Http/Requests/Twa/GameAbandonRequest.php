<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/twa/game/runs/{id}/abandon (docs/18 §12.8): `{confirm: true}` - the run is not counted, the client asks first. */
final class GameAbandonRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'boolean', 'accepted'],
        ];
    }
}
