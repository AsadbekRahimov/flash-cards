<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/twa/practice/sessions/{id}/replace (docs/16 §7.2, §10.4): the swap of a dictation whose audio does not play. */
final class PracticeReplaceRequest extends FormRequest
{
    public const REASON_AUDIO_UNAVAILABLE = 'audio_unavailable';

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'string', 'uuid'],
            'element_id' => ['required', 'string', 'max:16'],
            'reason' => ['required', 'string', Rule::in([self::REASON_AUDIO_UNAVAILABLE])],
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

    public function reason(): string
    {
        return $this->string('reason')->toString();
    }
}
