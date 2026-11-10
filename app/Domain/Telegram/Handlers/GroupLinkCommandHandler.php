<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Organizations\Services\OrgGroupLinkService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Enums\LinkTokenKind;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\TelegramLinkTokens;
use App\Models\TelegramGroup;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Linking a group to a center from the group itself (docs/13 §6.2, audit 2026-11-10 P1 / L8): `/start grp_<token>` — what Telegram
 * posts after the `?startgroup=` deep link of the cabinet adds the bot — or `/link <token>` typed where the bot already is. The rules
 * are {@see OrgGroupLinkService::linkFromChat()}; this class only parses, limits and answers.
 *
 * The answer to a refusal is ONE neutral text, whatever the reason: a stranger who found a code learns nothing about the chat, the
 * token or whose they are; the owner reads the reason in the cabinet. Attempts are limited per sender AND per chat (beyond —
 * silence: a group cannot be flooded with the bot's answers by its own members), on top of the dispatcher's inbound limit. An
 * anonymous administrator (Telegram posts as `GroupAnonymousBot`, no `from.id` of a person) is asked to send the command as
 * themselves — the sender must be matched to a staff account. `/link` in a private chat is told where the command goes. Runs in
 * groups that are not active yet (that is the point), so `requiresActiveGroup()` is false.
 */
final class GroupLinkCommandHandler implements UpdateHandler
{
    private const START = '~^/start(?:@\w+)?\s+grp_('.TelegramLinkTokens::TOKEN_PATTERN.')\s*$~';

    private const LINK = '~^/link(?:@\w+)?\s+(?:grp_)?('.TelegramLinkTokens::TOKEN_PATTERN.')\s*$~';

    /** `/link` with no / a malformed code: answered with the same neutral text, so the shape of a code is not confirmed either. */
    private const LINK_ANY = '~^/link(?:@\w+)?(\s|$)~';

    /** The user id Telegram puts in `from` for an anonymous group administrator (and the channel-post sender). */
    private const ANONYMOUS_ADMIN_ID = 1087968824;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly OrgGroupLinkService $links,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $message = $update['message'] ?? null;
        if (! is_array($message)) {
            return false;
        }

        $text = (string) ($message['text'] ?? '');
        $chatType = (string) ($message['chat']['type'] ?? '');

        if ($chatType === 'private') {
            return preg_match(self::LINK_ANY, $text) === 1;
        }

        if (! in_array($chatType, ['group', 'supergroup'], true)) {
            return false;
        }

        return preg_match(self::START, $text) === 1 || preg_match(self::LINK_ANY, $text) === 1;
    }

    public function requiresActiveGroup(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $message */
        $message = $update['message'];
        /** @var array<string, mixed> $chat */
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        /** @var array<string, mixed> $from */
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $chatId = (int) ($chat['id'] ?? 0);
        $fromId = (int) ($from['id'] ?? 0);

        if ($chatId === 0 || $fromId <= 0) {
            return;
        }

        if (! $this->withinLimit($fromId, $chatId)) {
            return;
        }

        if (($chat['type'] ?? '') === 'private') {
            $languageCode = $from['language_code'] ?? null;
            $this->reply($chatId, __('bot.link.use_in_group', [], $this->locales->fromLanguageCode(is_string($languageCode) ? $languageCode : null)));

            return;
        }

        if ($this->isAnonymousAdmin($message, $from)) {
            $this->reply($chatId, __('bot.link.group_anonymous', [], $this->localeOf($chatId)));

            return;
        }

        $token = $this->tokenFrom((string) ($message['text'] ?? ''));

        $outcome = $token === null
            ? null
            : $this->links->linkFromChat($token, $chat, $from);

        if ($outcome === null || ! $outcome->result->isSuccess() || $outcome->group === null || $outcome->organization === null) {
            $this->reply($chatId, __('bot.link.group_refused', [], $this->localeOf($chatId)));

            return;
        }

        $locale = $outcome->organization->locale;
        $text = __('bot.link.group_linked', ['title' => $outcome->group->title, 'organization' => $outcome->organization->name], $locale)
            ."\n\n".__($outcome->group->status === 'active' ? 'bot.link.group_next_active' : 'bot.link.group_next_pending', [], $locale);

        $this->reply($chatId, $text);
    }

    private function tokenFrom(string $text): ?string
    {
        if (preg_match(self::START, $text, $m) === 1 || preg_match(self::LINK, $text, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /** Both buckets are spent by every attempt; the first one that is full silences the attempt. */
    private function withinLimit(int $fromId, int $chatId): bool
    {
        $max = max(1, (int) config('organizations.telegram_link.attempts_per_minute', 10));
        $allowed = true;

        foreach (['bot-link:'.$fromId, 'bot-link-chat:'.$chatId] as $key) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $allowed = false;
            }
            RateLimiter::hit($key, 60);
        }

        return $allowed;
    }

    /**
     * A command posted by an anonymous administrator: Telegram sends `from` = the `GroupAnonymousBot` service account and a
     * `sender_chat` equal to the group. There is no person to match to a staff account — and the answer says only that.
     *
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $from
     */
    private function isAnonymousAdmin(array $message, array $from): bool
    {
        if ((int) ($from['id'] ?? 0) === self::ANONYMOUS_ADMIN_ID || ($from['username'] ?? null) === 'GroupAnonymousBot') {
            return true;
        }

        $senderChat = $message['sender_chat'] ?? null;

        return is_array($senderChat) && (int) ($senderChat['id'] ?? 0) === (int) ($message['chat']['id'] ?? 0);
    }

    private function localeOf(int $chatId): string
    {
        $group = TelegramGroup::query()->where('chat_id', $chatId)->first();

        return $group !== null ? $this->locales->forGroup($group) : $this->locales->default();
    }

    private function reply(int $chatId, string $text): void
    {
        try {
            $this->telegram->sendMessage($chatId, $text);
        } catch (TelegramApiException|TelegramForbiddenException $e) {
            Log::warning('telegram.group_link_reply_failed', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
        }
    }

    /** For tests and docs: the deep-link kind this handler serves. */
    public static function kind(): LinkTokenKind
    {
        return LinkTokenKind::Group;
    }
}
