<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Support;

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use Illuminate\Support\Facades\Log;

/**
 * The empty `answerCallbackQuery` that dismisses the spinner of a tapped inline button.
 *
 * It is cosmetic: Telegram refuses it for a query older than ~15 s or an already answered one,
 * and a retried job (HandleTelegramUpdate, tries = 3) always hits that. So a refusal must never
 * stop the real work of the handler — it is logged (the error code only, no ids or text) and
 * dropped.
 */
final class CallbackAcknowledger
{
    public function __construct(private readonly TelegramClient $telegram) {}

    /** @param array<string, mixed> $update an update carrying a `callback_query` */
    public function acknowledge(array $update): void
    {
        $this->answer((string) ($update['callback_query']['id'] ?? ''));
    }

    public function answer(string $callbackQueryId): void
    {
        if ($callbackQueryId === '') {
            return;
        }

        try {
            $this->telegram->answerCallbackQuery($callbackQueryId);
        } catch (TelegramApiException $e) {
            Log::warning('telegram.callback_ack_failed', ['code' => $e->getCode()]);
        }
    }
}
