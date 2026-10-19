<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Models\TelegramGroup;
use Illuminate\Support\Facades\Log;

/**
 * Without admin rights the bot does not see group messages or membership changes, so a group
 * where it is only a `member` works half-way. When the bot is added to a group we ask Telegram
 * (`getChatMember` of the bot itself) and, if it is not an administrator, post a warning to the
 * group in the group's language, where the teacher reads it.
 *
 * Never blocks anything: the group is registered either way, and a failed call (network, 4xx)
 * is only logged as a warning (exception messages are already free of the bot token).
 */
final class GroupBotAccessChecker
{
    private const ADMIN_STATUSES = ['administrator', 'creator'];

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
    ) {}

    /** Returns true when the warning was sent. */
    public function warnIfNotAdmin(TelegramGroup $group, ?int $botId = null): bool
    {
        $botId ??= $this->botIdFromToken();
        if ($botId === null) {
            Log::warning('telegram.group_bot_admin_check_skipped', ['chat_id' => $group->chat_id, 'reason' => 'bot_id_unknown']);

            return false;
        }

        try {
            $status = $this->telegram->getChatMemberStatus($group->chat_id, $botId);
        } catch (TelegramApiException $e) {
            Log::warning('telegram.group_bot_admin_check_failed', ['chat_id' => $group->chat_id, 'error' => $e->getMessage()]);

            return false;
        }

        if (in_array($status, self::ADMIN_STATUSES, true)) {
            return false;
        }

        try {
            $this->telegram->sendMessage(
                $group->chat_id,
                __('bot.group.bot_not_admin', [], $this->locales->forGroup($group)),
            );
        } catch (TelegramApiException|TelegramForbiddenException $e) {
            Log::warning('telegram.group_bot_admin_warning_failed', ['chat_id' => $group->chat_id, 'error' => $e->getMessage()]);

            return false;
        }

        return true;
    }

    /** A bot token is `<bot id>:<secret>`. */
    private function botIdFromToken(): ?int
    {
        $id = explode(':', (string) config('telegram.bot_token'), 2)[0];

        return ctype_digit($id) ? (int) $id : null;
    }
}
