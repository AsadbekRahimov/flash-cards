<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/twa/game/shop/{key}/buy (docs/18 §12.8): the purchase is paid once per `request_id`. */
final class GameBuyRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'string', 'uuid'],
        ];
    }

    /** The key of the request: a UUID in lower case (the ledger key is compared as a string). */
    public function requestId(): string
    {
        return strtolower($this->string('request_id')->toString());
    }
}
