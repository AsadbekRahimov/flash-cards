<?php

declare(strict_types=1);

namespace App\Domain\Practice\Enums;

/**
 * Which DM of the battle a `SendBattleDmJob` carries (the id is a battle id), docs/16 §8.7. One kind in the first release:
 * the results are not sent (both players are in the app).
 */
enum BattleDmKind: string
{
    /** To the invited friend, with a `web_app` button that opens the battle: "{name} invites you to a battle". */
    case Invited = 'invited';
}
