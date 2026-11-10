<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Support;

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use Illuminate\Support\Facades\Log;

/**
 * The `answerCallbackQuery` that dismisses the spinner of a tapped inline button: empty, or with a text for the tapper
 * alone (a refusal, shown as an alert) instead of a message that the whole group would see. A query can be answered once.
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
    public function acknowledge(array $update, ?string $alert = null): void
    {
        $this->answer((string) ($update['callback_query']['id'] ?? ''), $alert);
    }

    /** @param string|null $alert shown to the tapper only, as an alert; cut at the 200 characters Telegram allows */
    public function answer(string $callbackQueryId, ?string $alert = null): void
    {
        if ($callbackQueryId === '') {
            return;
        }

        try {
            if ($alert === null) {
                $this->telegram->answerCallbackQuery($callbackQueryId);
            } else {
                $this->telegram->answerCallbackQuery($callbackQueryId, mb_substr($alert, 0, 200), true);
            }
        } catch (TelegramApiException $e) {
            Log::warning('telegram.callback_ack_failed', ['code' => $e->getCode()]);
        }
    }
}
