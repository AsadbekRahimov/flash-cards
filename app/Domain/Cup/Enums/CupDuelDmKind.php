<?php

declare(strict_types=1);

namespace App\Domain\Cup\Enums;

/**
 * Which DM of a duel of a cup a `SendCupDuelDmJob` carries (the id is a duel id; docs/33 §5.7, D2). The result is not sent: both players
 * are in the app, and a public challenge gets its edit in the chat.
 */
enum CupDuelDmKind: string
{
    /** To the participant challenged by a private challenge, with a button into the challenge: "{name} challenges you". */
    case Challenged = 'challenged';

    /** To the challenger, once his challenge is accepted, with a button into the match: the room waits for him a few seconds only. */
    case Accepted = 'accepted';
}
