<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Support;

use App\Domain\Telegram\Services\BotButtons;

/**
 * One inline button of a message of the bot (docs/36 §3б), as {@see BotButtons} made it: a Mini App screen (`web_app`), a
 * `callback_data` or a plain link. It knows nothing about the chat: {@see BotButtons::keyboard()} turns a `web_app` button
 * into a `startapp` link outside a private chat.
 */
final readonly class BotButton
{
    public const KIND_WEB_APP = 'web_app';

    public const KIND_CALLBACK = 'callback_data';

    public const KIND_URL = 'url';

    /**
     * @param  self::KIND_*  $kind
     * @param  string  $value  the TWA address (`web_app`), the callback data or the link
     */
    public function __construct(
        public string $text,
        public string $kind,
        public string $value,
    ) {}

    public function isWebApp(): bool
    {
        return $this->kind === self::KIND_WEB_APP;
    }
}
