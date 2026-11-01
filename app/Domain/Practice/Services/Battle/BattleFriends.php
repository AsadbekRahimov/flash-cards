<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services\Battle;

use App\Domain\Engagement\Enums\FriendshipStatus;
use App\Domain\Engagement\Exceptions\FriendshipException;
use App\Domain\Engagement\Services\FriendshipService;
use App\Domain\Practice\Exceptions\BattleException;
use App\Models\Battle;
use App\Models\Friendship;
use App\Models\Learner;

/**
 * Who a learner may battle (docs/16 §8.2, §12): the other side of one of their OWN friendships, accepted, not blocked.
 * Shared by the invitation and the options; the friendship is read before any transaction and never locked (§9 rule 8).
 *
 *   friendship_not_found  404  not the learner's pair, or the friend is gone / blocked (a stranger's id looks the same)
 *   not_friends           403  the pair exists but is not an accepted friendship (pending, blocked by the learner)
 */
final class BattleFriends
{
    public function __construct(private readonly FriendshipService $friendships) {}

    /**
     * The friend behind a friendship id.
     *
     * @return array{0: Learner, 1: Friendship}
     *
     * @throws FriendshipException friendship_not_found
     * @throws BattleException not_friends
     */
    public function byFriendship(Learner $by, int $friendshipId): array
    {
        $friendship = $this->friendships->find($by, $friendshipId);

        return [$this->reachable($friendship->otherId($by->id)), $this->accepted($friendship)];
    }

    /**
     * The friend of a rematch: the other player of the finished battle, still an accepted friend.
     *
     * @return array{0: Learner, 1: Friendship}
     *
     * @throws FriendshipException friendship_not_found
     * @throws BattleException not_friends
     */
    public function ofRematch(Learner $by, Battle $source): array
    {
        $friend = $this->reachable($source->otherId($by->id));
        $friendship = $this->friendships->between($by, $friend) ?? throw BattleException::notFriends();

        return [$friend, $this->accepted($friendship)];
    }

    /** @throws FriendshipException friendship_not_found */
    private function reachable(int $learnerId): Learner
    {
        $friend = Learner::query()->find($learnerId);
        if (! $friend instanceof Learner || $friend->blocked_at !== null) {
            throw FriendshipException::notFound();
        }

        return $friend;
    }

    /** @throws BattleException not_friends */
    private function accepted(Friendship $friendship): Friendship
    {
        if ($friendship->status !== FriendshipStatus::Accepted->value) {
            throw BattleException::notFriends();
        }

        return $friendship;
    }
}
