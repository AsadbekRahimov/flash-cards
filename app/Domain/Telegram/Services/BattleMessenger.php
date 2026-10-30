<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Practice\Enums\BattleDmKind;

/**
 * What the bot says about battles (docs/16 §8.7): the DM "{name} invites you to a battle: {format}, {course · lesson}. The
 * invitation is open for 5 minutes" with one `web_app` button that opens `{twa.base_url}/twa/battles/{id}`.
 *
 * Skeleton (18.6): {@see SendBattleDmJob} calls {@see notify()}; the body - the re-read of the battle (`invited`, not
 * expired, else silence), the reachable recipient (not blocked, `dm_blocked_at IS NULL`), the `notifications_log` marker of
 * type `battle_dm` (`payload.key = invited:<battle_id>`) written BEFORE the Telegram call, a 403 that deletes the marker and
 * closes the DM (`dm_blocked_at`), the texts of `lang/{ru,uz}/battle.php` - is task 18.6b, with `BattleMessengerTest`.
 */
final class BattleMessenger
{
    public const NOTIFICATION_TYPE = 'battle_dm';

    /**
     * Background DM (SendBattleDmJob). Nothing is sent yet: 18.6b implements the delivery on the pattern of
     * {@see FriendsMessenger::notify()} / {@see FriendsMessenger::deliver()}.
     */
    public function notify(BattleDmKind $kind, int $battleId): void
    {
        // TODO 18.6b: BattleDmKind::Invited -> re-read the battle, check the recipient, write the marker, send the message with the web_app button.
    }
}
