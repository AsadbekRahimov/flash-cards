<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Support;

use App\Domain\Telegram\Handlers\PollCallbackHandler;

/**
 * Where {@see PollCallbackHandler} shows the answer to a tap: the message of the tap in the tapper's private chat, a photo (the caption
 * is edited) or a text, with the token of its buttons and the language of the delivery.
 */
final readonly class PollMessageEdit
{
    public function __construct(
        public int $chatId,
        public int $messageId,
        public bool $photo,
        public string $token,
        public string $locale,
    ) {}
}
