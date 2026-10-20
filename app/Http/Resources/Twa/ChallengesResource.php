<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Engagement\DTO\ChallengesOverview;
use Illuminate\Http\Request;

/**
 * GET /api/twa/challenges (docs/04 A.13): the open (pending / active) challenges and the latest finished ones.
 *
 * @property ChallengesOverview $resource
 */
final class ChallengesResource extends TwaResource
{
    /**
     * @return array{days: int, open: list<array<string, mixed>>, finished: list<array<string, mixed>>}
     */
    public function toArray(Request $request): array
    {
        $open = [];
        foreach ($this->resource->open as $entry) {
            $open[] = ChallengeResource::entry($entry);
        }
        $finished = [];
        foreach ($this->resource->finished as $entry) {
            $finished[] = ChallengeResource::entry($entry);
        }

        return ['days' => $this->resource->days, 'open' => $open, 'finished' => $finished];
    }
}
