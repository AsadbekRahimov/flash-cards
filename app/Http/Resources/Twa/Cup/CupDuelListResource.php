<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa\Cup;

use App\Domain\Cup\DTO\CupDuelBoard;
use App\Domain\Cup\DTO\CupDuelView;
use App\Domain\Practice\Support\IsoTime;
use App\Http\Resources\Twa\TwaResource;
use Illuminate\Http\Request;

/**
 * `GET /cups/{id}/duels` (docs/04 A.23d, D2): `incoming` - open challenges addressed to me; `open` - open challenges to anybody (not
 * mine); `mine` - my open challenge or null; `today` - my duels of today that are not open any more (accepted, done, void, declined,
 * expired, canceled), newest first; `availability` - may I challenge now (the same block as `me.duel` of `GET /cups/{id}`). Every duel is
 * a {@see CupDuelResource}.
 *
 * @property CupDuelBoard $resource
 */
final class CupDuelListResource extends TwaResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $board = $this->resource;
        $list = static fn (array $views): array => array_map(static fn (CupDuelView $view): array => CupDuelResource::fields($view), $views);

        return [
            'incoming' => $list($board->incoming),
            'open' => $list($board->open),
            'mine' => $board->mine === null ? null : CupDuelResource::fields($board->mine),
            'today' => $list($board->today),
            'availability' => $board->availability,
            'server_now' => IsoTime::format($board->now),
        ];
    }
}
