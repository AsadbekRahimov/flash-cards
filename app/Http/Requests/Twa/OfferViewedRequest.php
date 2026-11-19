<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Billing\Enums\OfferPlacement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/twa/billing/offer-viewed — where the Pro offer became visible: one of the closed list of placements (docs/04 A.8). */
final class OfferViewedRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'placement' => ['required', 'string', Rule::enum(OfferPlacement::class)],
        ];
    }

    public function placement(): OfferPlacement
    {
        return OfferPlacement::from($this->string('placement')->toString());
    }
}
