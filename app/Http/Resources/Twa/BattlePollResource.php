<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Practice\DTO\BattlePollView;
use Illuminate\Http\Request;

/**
 * GET /api/twa/practice/battles/{id}/poll (docs/16 §7.4) and the `poll` of an answer: at most 600 bytes, read every two seconds.
 * The shape is {@see BattlePollView::toArray()} AS IT IS - the size budget is checked on that very array.
 *
 * @property BattlePollView $resource
 */
final class BattlePollResource extends TwaResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
