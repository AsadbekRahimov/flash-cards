<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Practice\Support\IsoTime;
use App\Models\GameCityProgress;
use Illuminate\Http\Request;

/**
 * The answer of POST /game/cities/{stage}/shared (docs/04 A.18).
 *
 * @property GameCityProgress $resource
 */
final class GameCityResource extends TwaResource
{
    /** @return array{stage_id: int, shared_at: string|null} */
    public function toArray(Request $request): array
    {
        return ['stage_id' => $this->resource->stage_id, 'shared_at' => $this->resource->shared_at === null ? null : IsoTime::format($this->resource->shared_at)];
    }
}
