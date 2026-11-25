<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Posts the "open the Mini App" button of a just-opened group session (training / exam).
 *
 * If Telegram definitely refused the message (API answer 4xx, or the bot cannot write to the chat),
 * nobody can see the session: `$cancel` withdraws it and the teacher gets `$failureText`, so a retry of
 * the command works (an exam would otherwise stay `open` and answer `already_open`). A transport
 * failure (timeout, 5xx) may have been delivered: the session is kept and the failure is logged.
 * The button is sent immediately, never through the deferred rate-limit queue: a refusal of a queued
 * message would be invisible here and the session would stay `open`.
 *
 * docs/34 §4в п. 2, 8: a 429 with `retry_after` <= 3 s is waited out and the button sent once more; a longer one (or a second 429)
 * withdraws the session WITHOUT the failure text (it would get the 429 too). A 403 withdraws it and turns the group `disabled`.
 */
final class GroupSessionAnnouncer
{
    /** A 429 asking to wait this long at most is waited out in the request and the button is sent once more (docs/34 §4в п. 2). */
    public const MAX_RATE_LIMIT_WAIT_SECONDS = 3;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly GroupBotRemoval $groups,
    ) {}

    /**
     * @param  Closure(): void  $cancel  withdraws the session that was just opened (not called if it pre-existed)
     * @param  int|null  $telegramGroupId  the internal `telegram_groups.id`, for the log lines (the raw chat id is not logged)
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
        ?int $telegramGroupId = null,
    ): bool {
        $send = fn () => $this->telegram->sendWebAppButton(
            chatId: $chatId,
            text: $text,
            buttonText: $buttonText,
            url: $url,
            parseMode: $parseMode,
            immediate: true,
        );

        try {
            try {
                $send();
            } catch (TelegramRateLimitedException $e) {
                if ($e->retryAfter > self::MAX_RATE_LIMIT_WAIT_SECONDS) {
                    throw $e;
                }

                // A short flood wait: wait it out once and repeat the same send (the first one was refused, it cannot double).
                Sleep::for($e->retryAfter)->seconds();
                $send();
            }

            return true;
        } catch (TelegramRateLimitedException $e) {
            // Too long to wait in a command, or a 429 again: nobody sees the session. The text about the failure would get a 429
            // as well, so the chat is not written to; the teacher simply repeats the command.
            Log::warning('telegram.group_session_announce_rate_limited', ['telegram_group_id' => $telegramGroupId, 'retry_after' => $e->retryAfter]);
            $cancel();

            return false;
        } catch (TelegramForbiddenException $e) {
            // The bot is not in the group any more (docs/34 §4в п. 8): the session goes, the group goes `disabled`, and there is
            // no chat to explain it in.
            $cancel();
            $this->groups->disableChat($chatId, $e, 'group_session');

            return false;
        } catch (TelegramApiException $e) {
            if (! $e->isApiResponse()) {
                Log::warning('telegram.group_session_announce_uncertain', ['telegram_group_id' => $telegramGroupId, 'error' => $e->getMessage()]);

                return true;
            }

            Log::warning('telegram.group_session_announce_failed', ['telegram_group_id' => $telegramGroupId, 'error' => $e->getMessage()]);
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
