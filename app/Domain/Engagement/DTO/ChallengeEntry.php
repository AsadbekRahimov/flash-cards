<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use Carbon\CarbonInterface;

/**
 * A challenge as seen by one of its two sides. Of the other learner only the first name leaves the server;
 * `friendshipId` is the handle for "challenge again" (null once the friendship is gone).
 */
final readonly class ChallengeEntry
{
    public function __construct(
        public int $id,
        public string $status,
        public string $metric,
        public int $days,
        /** `challenger` (you proposed it) or `opponent` (you were challenged). */
        public string $role,
        public ?string $opponentName,
        public ?int $friendshipId,
        public ?string $startsOn,
        public ?string $endsOn,
        public ?CarbonInterface $createdAt,
        /** Your score: live while active, final once finished; null before the start / when never played. */
        public ?int $myScore,
        public ?int $theirScore,
        /** `won` / `lost` / `draw` once finished; null otherwise. */
        public ?string $result,
    ) {}
}
