<?php

declare(strict_types=1);

namespace Tests\Support;

use Error;
use Telegram\Bot\Api;
use Telegram\Bot\TelegramResponse;

/**
 * Telegram SDK that refuses to leave the process: a test that reaches the real Bot API through the concrete
 * `IrazasyedTelegramClient` fails loudly (an `Error`, not swallowed by the client's exception mapping)
 * instead of getting a network answer that differs between a laptop and CI.
 */
final class OfflineTelegramApi extends Api
{
    public function __construct()
    {
        parent::__construct('0:offline');
    }

    /** @param array<string, mixed> $params */
    protected function sendRequest(string $method, string $endpoint, array $params = []): TelegramResponse
    {
        throw new Error("A test made a real Telegram Bot API request ({$endpoint}); bind a fake TelegramClient or an SDK double.");
    }
}
