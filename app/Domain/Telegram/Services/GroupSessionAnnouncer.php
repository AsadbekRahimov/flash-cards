<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Posts the "open the Mini App" button of a just-opened group session (training / exam).
 *
 * If Telegram definitely refused the message (API answer 4xx, or the bot cannot write to the chat),
 * nobody can see the session: `$cancel` withdraws it and the teacher gets `$failureText`, so a retry of
 * the command works (an exam would otherwise stay `open` and answer `already_open`). A transport
 * failure (timeout, 5xx) may have been delivered: the session is kept and the failure is logged.
 * The button is sent immediately, never through the deferred rate-limit queue: a refusal of a queued
 * message would be invisible here and the session would stay `open`.
 */
final class GroupSessionAnnouncer
{
    public function __construct(private readonly TelegramClient $telegram) {}

    /**
     * @param  Closure(): void  $cancel  withdraws the session that was just opened (not called if it pre-existed)
     * @return bool true when the button was sent (or may have been)
     */
    public function announce(
        int $chatId,
        string $text,
        string $buttonText,
        string $url,
        string $failureText,
        Closure $cancel,
        ?string $parseMode = null,
    ): bool {
        try {
            $this->telegram->sendWebAppButton(
                chatId: $chatId,
                text: $text,
                buttonText: $buttonText,
                url: $url,
                parseMode: $parseMode,
                immediate: true,
            );

            return true;
        } catch (TelegramApiException|TelegramForbiddenException $e) {
            if ($e instanceof TelegramApiException && ! $e->isApiResponse()) {
                Log::warning('telegram.group_session_announce_uncertain', ['chat_id' => $chatId, 'error' => $e->getMessage()]);

                return true;
            }

            Log::warning('telegram.group_session_announce_failed', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
            $cancel();

            try {
                $this->telegram->sendMessage($chatId, $failureText);
            } catch (TelegramApiException|TelegramForbiddenException) {
                // the chat is unreachable: the log above is all that is left
            }

            return false;
        }
    }
}
