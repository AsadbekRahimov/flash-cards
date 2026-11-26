<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Exceptions;

/**
 * Telegram answered 400 with `parameters.migrate_to_chat_id`: the group was upgraded to a supergroup and lives under
 * `newChatId` now (core.telegram.org/bots/api#responseparameters). The request was NOT carried out (`isApiResponse()`).
 *
 * The client handles it itself for sends: it moves `telegram_groups.chat_id` to the new id and repeats the send once in the new
 * chat (docs/33 §4.6). A caller sees this exception only when that is impossible: an edit (message ids of the old chat do not exist
 * in the new one) or a second migration answer in a row. Then it is an ordinary definite refusal: the marker goes, the exception is
 * rethrown, and the retried job reads the group's new `chat_id`.
 *
 * Neither chat id goes into the message (logs, `failed_jobs.exception`).
 */
final class TelegramChatMigratedException extends TelegramApiException
{
    public function __construct(public readonly int $newChatId, string $method = 'request')
    {
        parent::__construct(sprintf('Telegram API refused %s: the group chat was migrated to a supergroup', $method), 400);
        $this->apiResponse = true;
    }
}
