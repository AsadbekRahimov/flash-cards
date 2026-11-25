<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa\Cup;

use App\Domain\Cup\DTO\CupContext;
use App\Http\Resources\Twa\TwaResource;
use Illuminate\Http\Request;

/**
 * `GET /cups` (docs/04 A.23): "my cups" - the open ones first, then the last ten finished or canceled; each with the learner's own
 * result (his total and, after the finish, his place).
 *
 * @property list<CupContext> $resource
 */
final class CupListResource extends TwaResource
{
    /** @return array{cups: list<array<string, mixed>>} */
    public function toArray(Request $request): array
    {
        $cups = [];
        foreach ($this->resource as $context) {
            $mine = $context->participant;
            $cups[] = [
                ...CupFields::of($context->cup),
                'me' => $mine === null ? null : [
                    'participant_id' => $mine->id,
                    'display_name' => $mine->display_name,
                    'status' => $mine->status->value,
                    'total' => $mine->best_total,
                    'days_played' => $mine->days_played,
                    'final_rank' => $mine->final_rank,
                ],
            ];
        }

        return ['cups' => $cups];
    }
}
