<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa\Cup;

use App\Domain\Practice\Support\IsoTime;
use App\Http\Resources\Twa\TwaResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * `GET /cups/{id}/rounds/today` (docs/04 A.23): `{state: not_started | active | done | not_available, day, attempt_id, points,
 * expires_at, server_now}` - no write and no call to Telegram.
 *
 * @property array{state: string, day: int|null, attempt_id: int|null, points: int|null, expires_at: string|null} $resource
 */
final class CupTodayResource extends TwaResource
{
    /** @return array{state: string, day: int|null, attempt_id: int|null, points: int|null, expires_at: string|null, server_now: string} */
    public function toArray(Request $request): array
    {
        return [...$this->resource, 'server_now' => IsoTime::format(CarbonImmutable::now())];
    }
}
