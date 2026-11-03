<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/twa/game/runs/{id}/booster (docs/18 §12.8): a booster of the inventory, spent once per `request_id`; `element_id` is for a hint (default: the current element). */
final class GameBoosterRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'string', 'uuid'],
            'item_key' => ['required', 'string', 'max:48', 'regex:/\A[a-z0-9][a-z0-9_]{0,47}\z/'],
            'element_id' => ['nullable', 'string', 'max:16'],
        ];
    }

    /** The key of the request: a UUID in lower case (PostgreSQL stores it that way, SQLite compares strings). */
    public function requestId(): string
    {
        return strtolower($this->string('request_id')->toString());
    }

    public function itemKey(): string
    {
        return $this->string('item_key')->toString();
    }

    public function elementId(): ?string
    {
        $id = $this->input('element_id');

        return is_string($id) && $id !== '' ? $id : null;
    }
}
