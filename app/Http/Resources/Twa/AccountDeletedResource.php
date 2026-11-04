<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * POST /api/twa/me/delete (docs/04 A.1a): the account is soft-deleted; the learner can still come back with `/start`
 * until `restorable_until`, then the data is deleted for good.
 *
 * @property CarbonImmutable $resource the moment the account is deleted for good
 */
final class AccountDeletedResource extends TwaResource
{
    /** @return array{deleted: true, restorable_until: string} */
    public function toArray(Request $request): array
    {
        return [
            'deleted' => true,
            'restorable_until' => $this->resource->toIso8601String(),
        ];
    }
}
