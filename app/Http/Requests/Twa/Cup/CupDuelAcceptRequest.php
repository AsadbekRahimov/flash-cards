<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa\Cup;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/twa/cups/{id}/duels/{duel}/accept (docs/04 A.23d): `{expect_rated}` - the status the screen showed (rated or friendly). The
 * server decides it again and refuses a difference: 409 `cup_duel_rating_changed {rated}`, the screen shows the new status and the
 * learner accepts once more.
 */
final class CupDuelAcceptRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'expect_rated' => ['required', 'boolean'],
        ];
    }

    public function expectRated(): bool
    {
        return $this->boolean('expect_rated');
    }
}
