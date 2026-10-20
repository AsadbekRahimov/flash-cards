<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use Carbon\CarbonInterface;

/**
 * One row of the friends screen: a friendship as seen by one side. Of the other learner only the first
 * name, the visible streak and the number of learned words leave the server (no ids, usernames, tz).
 */
final readonly class FriendEntry
{
    public function __construct(
        public int $friendshipId,
        public ?string $firstName,
        public int $streak,
        public int $wordsLearned,
        public ?CarbonInterface $since,
    ) {}
}
