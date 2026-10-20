<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

/** GET /api/twa/friends: the learner's friends and the open requests in both directions. */
final readonly class FriendsOverview
{
    /**
     * @param  list<FriendEntry>  $friends
     * @param  list<FriendRequestEntry>  $incoming  asked of this learner
     * @param  list<FriendRequestEntry>  $outgoing  asked by this learner
     */
    public function __construct(
        public ?string $inviteLink,
        public int $limit,
        public array $friends,
        public array $incoming,
        public array $outgoing,
    ) {}
}
