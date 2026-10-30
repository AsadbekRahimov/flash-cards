<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/twa/practice/battles/{id}/audio-failed (docs/16 §7.4, §10.3): a clip of the match does not play. */
final class BattleAudioFailedRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'string', 'uuid'],
            'element_id' => ['required', 'string', 'max:16'],
        ];
    }

    public function requestId(): string
    {
        return strtolower($this->string('request_id')->toString());
    }

    public function elementId(): string
    {
        return $this->string('element_id')->toString();
    }
}
