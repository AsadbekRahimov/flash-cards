<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/twa/practice/battles/{id}/surrender (docs/16 §7.4): the one action with a body, `{confirm: true}` - giving up is
 * not a thing to do by a stray request. The other actions (accept, decline, cancel, ready) take no body.
 */
final class BattleSurrenderRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'accepted'],
        ];
    }
}
