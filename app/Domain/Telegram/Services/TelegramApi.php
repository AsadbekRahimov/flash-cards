<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use Telegram\Bot\Api;

/**
 * @deprecated Use App\Domain\Telegram\Contracts\TelegramClient instead.
 */
class TelegramApi extends IrazasyedTelegramClient
{
    public function __construct()
    {
        parent::__construct(new Api((string) config('telegram.bot_token')), new TelegramKeyboardFactory);
    }
}
