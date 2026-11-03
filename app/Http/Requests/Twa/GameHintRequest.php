<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/twa/game/runs/{id}/hint (docs/18 §12.8): the hint of the bazaar for the element, bought once per `request_id`. */
final class GameHintRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'string', 'uuid'],
            'element_id' => ['required', 'string', 'max:16'],
        ];
    }

    /** The key of the request: a UUID in lower case (PostgreSQL stores it that way, SQLite compares strings). */
    public function requestId(): string
    {
        return strtolower($this->string('request_id')->toString());
    }

    public function elementId(): string
    {
        return $this->string('element_id')->toString();
    }
}
