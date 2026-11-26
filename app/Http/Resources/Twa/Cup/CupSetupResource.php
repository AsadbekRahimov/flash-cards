<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa\Cup;

use App\Domain\Localization\Services\LocaleResolver;
use App\Http\Resources\Twa\TwaResource;
use App\Models\Cup;
use Illuminate\Http\Request;

/**
 * The screens of an administrator of the chat (docs/04 A.23): `GET /cups/setup/{code}`, `POST /cups/setup/{code}/publish`,
 * `PATCH /cups/{id}/settings`, `POST /cups/{id}/cancel` - the cup with what may be set: the level and the language of the messages
 * (a draft only), the summaries (any open cup). `cancel_reason` after a cancel.
 *
 * @property Cup $resource
 */
final class CupSetupResource extends TwaResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $cup = $this->resource;

        return [
            ...CupFields::of($cup),
            'locale' => $cup->locale,
            'summary_minute' => $cup->summary_minute,
            'cancel_reason' => $cup->cancel_reason?->value,
            'options' => [
                'levels' => array_values(array_map('strval', (array) config('cup.levels', []))),
                'locales' => app(LocaleResolver::class)->supported(),
            ],
            'rules' => CupFields::rules($cup),
        ];
    }
}
