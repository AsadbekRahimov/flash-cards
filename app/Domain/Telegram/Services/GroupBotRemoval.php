<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Models\TelegramGroup;
use App\Observers\TelegramGroupObserver;
use Illuminate\Support\Facades\Log;

/**
 * A send to a group got Telegram's 403 (`bot was kicked`, `bot is not a member`) or 400 `chat not found` (docs/34 §4в п. 3, B4): the
 * bot is not in the chat any more, whether or not its `my_chat_member` reached us. The group goes `disabled` - through Eloquent,
 * so {@see TelegramGroupObserver} drops the members' cached plans (a sponsored Pro ends at once), exactly as `MyChatMemberHandler`
 * does on `left` / `kicked`. A group already `disabled` is not written again. `bot_status` is left alone: it mirrors
 * `my_chat_member` only (docs/33 §2.1). The caller ends its job without an error and without its marker.
 */
final class GroupBotRemoval
{
    /** @param string $source who found out (`leaderboard`, `class_caravan_summary`, …) - for the log */
    public function disable(TelegramGroup|int $group, TelegramForbiddenException $e, string $source): void
    {
        $id = $group instanceof TelegramGroup ? $group->id : $group;

        $this->disableRow(TelegramGroup::query()->whereKey($id)->first(), $e, $source);
    }

    /** The same by the chat id (a deferred message knows only that). */
    public function disableChat(int $chatId, TelegramForbiddenException $e, string $source): void
    {
        $this->disableRow(TelegramGroup::query()->where('chat_id', $chatId)->first(), $e, $source);
    }

    private function disableRow(?TelegramGroup $group, TelegramForbiddenException $e, string $source): void
    {
        Log::warning('telegram.group_forbidden', [
            'telegram_group_id' => $group?->id,
            'reason' => $e->reason,
            'source' => $source,
        ]);

        if ($group !== null && $group->status !== 'disabled') {
            $group->update(['status' => 'disabled']);
        }
    }
}
