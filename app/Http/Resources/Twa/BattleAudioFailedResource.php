<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Practice\DTO\BattleView;
use Illuminate\Http\Request;

/**
 * POST /api/twa/practice/battles/{id}/audio-failed (docs/16 §7.4, §10.3): `verified` - the server looked and the file is indeed
 * missing (the match is voided); false - the file is there, the trouble is local and the match goes on. `battle` is the match as it
 * is now, so the screen sees the voiding at once.
 *
 * @property array{verified: bool, battle: BattleView} $resource
 */
final class BattleAudioFailedResource extends TwaResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'verified' => $this->resource['verified'],
            'battle' => (new BattleResource($this->resource['battle']))->toArray($request),
        ];
    }
}
