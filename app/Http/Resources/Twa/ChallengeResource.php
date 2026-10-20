<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Engagement\DTO\ChallengeEntry;
use Illuminate\Http\Request;

/**
 * One challenge as its viewer sees it (docs/04 A.13): the answer of propose / accept / decline and the
 * element of the lists of {@see ChallengesResource}.
 *
 * @property ChallengeEntry $resource
 */
final class ChallengeResource extends TwaResource
{
    /**
     * @return array{id: int, status: string, metric: string, days: int, role: string, friend_name: string|null, friendship_id: int|null, starts_on: string|null, ends_on: string|null, created_at: string|null, my_score: int|null, their_score: int|null, result: string|null}
     */
    public function toArray(Request $request): array
    {
        return self::entry($this->resource);
    }

    /**
     * @return array{id: int, status: string, metric: string, days: int, role: string, friend_name: string|null, friendship_id: int|null, starts_on: string|null, ends_on: string|null, created_at: string|null, my_score: int|null, their_score: int|null, result: string|null}
     */
    public static function entry(ChallengeEntry $e): array
    {
        return [
            'id' => $e->id,
            'status' => $e->status,
            'metric' => $e->metric,
            'days' => $e->days,
            'role' => $e->role,
            'friend_name' => $e->opponentName,
            'friendship_id' => $e->friendshipId,
            'starts_on' => $e->startsOn,
            'ends_on' => $e->endsOn,
            'created_at' => $e->createdAt?->toIso8601String(),
            'my_score' => $e->myScore,
            'their_score' => $e->theirScore,
            'result' => $e->result,
        ];
    }
}
