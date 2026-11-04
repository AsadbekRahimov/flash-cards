<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Operations\Support\PureCache;
use Closure;
use Throwable;

/**
 * The answers of `getChatMember` for the check "is the bot an administrator of this group" (docs/19 K7), in the pure cache:
 * key `tg:member:v1:{chat id}:{user id}`, the status string as the value, {@see TTL} seconds. A Bot API call is a network round
 * trip that the same group needs again when its bot is added and then linked to a center, or re-linked by another owner.
 *
 * What is cached is the status of the BOT, which Telegram announces itself: every change of it is a `my_chat_member` update,
 * and the handler of that update drops the entry ({@see forget()}), so a promotion to administrator is known to the very
 * next check and a stale "member" never sends a false warning.
 *
 * What is NOT cached, on purpose (docs/19 §3 rule 9): the status of a PERSON. `OrgGroupLinkService` asks Telegram afresh whether
 * the OWNER is an administrator of the group before it lets them link it, and `chat_member` updates (a person's membership)
 * touch this cache not at all: a status that was true five minutes ago must not open that door, and a bot that is not an
 * administrator receives no `chat_member` update that could close it again.
 *
 * The cache is a shortcut and never a dependency: when the pure store cannot be reached the call goes to Telegram as it did
 * before the cache existed, and the failure is reported, not thrown (a Telegram update must not fail because `redis-cache` is
 * down). A failed Telegram call is never stored: the exception goes to the caller as it did.
 */
final class ChatMemberStatusCache
{
    private const PREFIX = 'tg:member:v1:';

    /** 10 minutes: long enough for the add-then-link sequence, short enough to bound a missed update. */
    public const TTL = 600;

    /**
     * The status from the cache, else from `$fetch` (one Bot API call), stored for {@see TTL} seconds.
     *
     * @param  Closure(): string  $fetch
     */
    public function status(int|string $chatId, int $userId, Closure $fetch): string
    {
        $key = self::key($chatId, $userId);

        try {
            $cached = PureCache::store()->get($key);
        } catch (Throwable $e) {
            report($e);

            return $fetch();
        }

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $status = $fetch();

        try {
            PureCache::store()->put($key, $status, self::TTL);
        } catch (Throwable $e) {
            report($e);
        }

        return $status;
    }

    /** The bot's membership changed (`my_chat_member`): the next check asks Telegram. */
    public function forget(int|string $chatId, int $userId): void
    {
        PureCache::store()->forget(self::key($chatId, $userId));
    }

    /** The bot's own user id: a bot token is `<bot id>:<secret>`. */
    public function botId(): ?int
    {
        $id = explode(':', (string) config('telegram.bot_token'), 2)[0];

        return ctype_digit($id) ? (int) $id : null;
    }

    public static function key(int|string $chatId, int $userId): string
    {
        return self::PREFIX."{$chatId}:{$userId}";
    }
}
