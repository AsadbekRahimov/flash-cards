<?php

declare(strict_types=1);

namespace App\Domain\Cup\Support;

use App\Domain\Cup\Enums\CupPauseReason;
use App\Models\TelegramGroup;

/**
 * The switch of the feature and the pause of a group (docs/33 §3.1, §9.1).
 *
 * - `CUP_ACCESS` (`config('cup.access')`): `off` - no group; `pilot` - the groups with `meta.cup_pilot = true`; `all` - every group.
 * - The pause is a condition, not a status: the bot is out of the chat (`bot_status` left / kicked) or the platform forbade cups in
 *   the group (`meta.cups_blocked`). A paused cup accepts no new round and no new participant; its days run on.
 */
final class CupAccess
{
    public static function allowsGroup(TelegramGroup $group): bool
    {
        return match ((string) config('cup.access', 'off')) {
            'all' => true,
            'pilot' => ($group->meta['cup_pilot'] ?? false) === true,
            default => false,
        };
    }

    public static function pauseReason(TelegramGroup $group): ?CupPauseReason
    {
        if (($group->meta['cups_blocked'] ?? false) === true) {
            return CupPauseReason::Blocked;
        }

        return in_array($group->bot_status, ['left', 'kicked'], true) ? CupPauseReason::BotRemoved : null;
    }
}
