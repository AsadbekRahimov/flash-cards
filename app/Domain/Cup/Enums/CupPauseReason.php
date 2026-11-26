<?php

declare(strict_types=1);

namespace App\Domain\Cup\Enums;

/**
 * Why a cup is paused (docs/33 §3.1): not a status but a condition of its group - the bot is out of the chat (`bot_status` left /
 * kicked) or an administrator of the platform forbade cups there (`telegram_groups.meta.cups_blocked`).
 */
enum CupPauseReason: string
{
    case BotRemoved = 'bot_removed';
    case Blocked = 'blocked';
}
