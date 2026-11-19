<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Analytics\Services\ClientTimingRecorder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/twa/client-timing — the timing marks of one open of the Mini App (docs/04 A.21). A closed set of keys: anything else is
 * refused (422), so that no free text and nothing about the device can ride along. `platform` is any short string (the client passes
 * `Telegram.WebApp.platform` through) and is normalised to the closed list by the service; `entry` is a closed list.
 */
final class ClientTimingRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $rules = [
            'open_id' => ['required', 'string', 'uuid'],
            'platform' => ['required', 'string', 'max:32'],
            'cold' => ['required', 'boolean'],
            'entry' => ['required', 'string', Rule::in(ClientTimingRecorder::ENTRIES)],
        ];

        foreach (ClientTimingRecorder::MARKS as $mark) {
            $rules[$mark] = ['sometimes', 'integer', 'min:0', 'max:'.ClientTimingRecorder::MAX_OFFSET_MS];
        }

        return $rules;
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $allowed = ['open_id', 'platform', 'cold', 'entry', ...ClientTimingRecorder::MARKS];
                foreach (array_keys($this->all()) as $key) {
                    if (! in_array($key, $allowed, true)) {
                        $validator->errors()->add((string) $key, __('validation.prohibited', ['attribute' => (string) $key]));
                    }
                }
            },
        ];
    }

    public function openId(): string
    {
        return $this->string('open_id')->toString();
    }

    public function platform(): string
    {
        return $this->string('platform')->toString();
    }

    public function cold(): bool
    {
        return $this->boolean('cold');
    }

    public function entry(): string
    {
        return $this->string('entry')->toString();
    }

    /** @return array<string, int> the offsets that were sent */
    public function marks(): array
    {
        $marks = [];
        foreach (ClientTimingRecorder::MARKS as $mark) {
            if ($this->has($mark)) {
                $marks[$mark] = $this->integer($mark);
            }
        }

        return $marks;
    }
}
