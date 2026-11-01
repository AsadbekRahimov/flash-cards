<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Practice\DTO\BattleListView;
use Illuminate\Http\Request;

/**
 * GET /api/twa/practice/battles (docs/16 §7.3): the match the learner is busy with (compact: `id, status, mode, role, course,
 * lesson, opponent` - the screen reads the battle itself with `GET …/{id}`), the invitations to them that have not run out, the
 * last matches that are over. The rows are already in the form of the wire, the dates in them ISO 8601 strings.
 *
 * @property BattleListView $resource
 */
final class BattleListResource extends TwaResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $list = $this->resource;

        return [
            'active' => $list->active,
            'incoming' => $list->incoming,
            'finished' => $list->finished,
        ];
    }
}
