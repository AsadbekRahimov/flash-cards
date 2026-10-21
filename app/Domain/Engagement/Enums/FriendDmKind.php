<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/** Which background DM of the friends feature a `SendFriendDmJob` carries (the id is a friendship / challenge id). */
enum FriendDmKind: string
{
    /** To the side that asked: "X accepted your friend request" (friendship id). */
    case FriendAccepted = 'friend_accepted';
    /** To the challenged friend, with Accept / Decline buttons (challenge id). */
    case ChallengeProposed = 'challenge_proposed';
    /** To the challenger: "X accepted the challenge" (challenge id). */
    case ChallengeAccepted = 'challenge_accepted';
    /** To both sides: the result (challenge id). */
    case ChallengeFinished = 'challenge_finished';
}
