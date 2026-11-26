<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa\Cup;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/twa/cups/{id}/settings (docs/04 A.23, docs/33 §6.5): any of `level`, `locale` (a draft only - else 409
 * `cup_already_published`), `summaries_enabled`, `summary_minute` (0-1439, the minute of the local day).
 */
final class CupSettingsRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'level' => ['sometimes', 'string', 'max:2'],
            'locale' => ['sometimes', 'string', 'max:5'],
            'summaries_enabled' => ['sometimes', 'boolean'],
            'summary_minute' => ['sometimes', 'integer', 'min:0', 'max:1439'],
        ];
    }

    /** @return array{level?: string, locale?: string, summaries_enabled?: bool, summary_minute?: int} */
    public function settings(): array
    {
        $settings = [];
        if ($this->has('level')) {
            $settings['level'] = $this->string('level')->toString();
        }
        if ($this->has('locale')) {
            $settings['locale'] = $this->string('locale')->toString();
        }
        if ($this->has('summaries_enabled')) {
            $settings['summaries_enabled'] = $this->boolean('summaries_enabled');
        }
        if ($this->has('summary_minute')) {
            $settings['summary_minute'] = $this->integer('summary_minute');
        }

        return $settings;
    }
}
