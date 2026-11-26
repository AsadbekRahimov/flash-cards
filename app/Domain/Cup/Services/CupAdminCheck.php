<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Cup\Enums\CupPauseReason;
use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Models\TelegramGroup;

/**
 * "Is this Telegram user an administrator of the chat?" (docs/33 §3.3) - asked of Telegram EVERY time, never cached and never taken
 * from the client: the user id is `callback_query.from.id` of the signed webhook or `learners.telegram_user_id` of the JWT, both
 * resolved by the caller. Only `creator` and `administrator` count. A failure of Telegram is a refusal, never a "yes".
 */
final class CupAdminCheck
{
    private const ADMIN_STATUSES = ['creator', 'administrator'];

    public function __construct(private readonly TelegramClient $telegram) {}

    /**
     * @throws CupException cup_not_chat_admin | cup_paused {bot_removed} | cup_unverifiable {telegram_unavailable}
     */
    public function assertChatAdmin(TelegramGroup $group, int $telegramUserId): void
    {
        if (! $this->isChatAdmin($group, $telegramUserId)) {
            throw CupException::notChatAdmin();
        }
    }

    /**
     * @throws CupException cup_paused {bot_removed} | cup_unverifiable {telegram_unavailable}
     */
    public function isChatAdmin(TelegramGroup $group, int $telegramUserId): bool
    {
        if ($telegramUserId <= 0) {
            return false;
        }

        try {
            $status = $this->telegram->getChatMember($group->chat_id, $telegramUserId)->status;
        } catch (TelegramApiException $e) {
            if ($e->getCode() === 403) {
                throw CupException::paused(CupPauseReason::BotRemoved->value);
            }
            if (CupMembershipGate::saysNotMember($e)) {
                return false;
            }

            throw CupException::telegramUnavailable();
        }

        return in_array($status, self::ADMIN_STATUSES, true);
    }
}
