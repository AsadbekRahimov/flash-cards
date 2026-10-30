<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use Illuminate\Foundation\Http\FormRequest;

/** GET /api/twa/practice/battles/{id}/round?no=2 (docs/16 §7.4): the number of the round to read. */
final class BattleRoundRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'no' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }

    public function no(): int
    {
        return $this->integer('no');
    }
}
