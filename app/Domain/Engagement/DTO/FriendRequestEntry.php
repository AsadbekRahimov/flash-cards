<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use Carbon\CarbonInterface;

/** A pending friend request: only a first name of the other side. */
final readonly class FriendRequestEntry
{
    public function __construct(
        public int $friendshipId,
        public ?string $firstName,
        public ?CarbonInterface $requestedAt,
    ) {}
}
