<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Game\Enums\ItemSlot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/twa/game/profile/equip (docs/18 §12.8): `item_key = null` takes the slot off. */
final class GameEquipRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'slot' => ['required', 'string', Rule::in(array_column(ItemSlot::cases(), 'value'))],
            // Present (even as null): an omitted key is a mistake of the client, not a request to take the slot off.
            'item_key' => ['present', 'nullable', 'string', 'max:48'],
        ];
    }

    public function slot(): ItemSlot
    {
        return ItemSlot::from($this->string('slot')->toString());
    }

    public function itemKey(): ?string
    {
        $key = $this->input('item_key');

        return is_string($key) ? $key : null;
    }
}
