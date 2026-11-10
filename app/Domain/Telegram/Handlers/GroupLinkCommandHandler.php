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
 * token or whose they are; the owner reads the reason in the cabinet. Attempts are limited per sender (beyond — silence), on top of
 * the dispatcher's inbound limit. Runs in groups that are not active yet (that is the point), so `requiresActiveGroup()` is false.
 */
final class GroupLinkCommandHandler implements UpdateHandler
{
    private const START = '~^/start(?:@\w+)?\s+grp_('.TelegramLinkTokens::TOKEN_PATTERN.')\s*$~';

    private const LINK = '~^/link(?:@\w+)?\s+(?:grp_)?('.TelegramLinkTokens::TOKEN_PATTERN.')\s*$~';

    /** `/link` with no / a malformed code: answered with the same neutral text, so the shape of a code is not confirmed either. */
    private const LINK_ANY = '~^/link(?:@\w+)?(\s|$)~';

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

        if (! in_array($message['chat']['type'] ?? '', ['group', 'supergroup'], true)) {
            return false;
        }

        $text = (string) ($message['text'] ?? '');

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

        if (! $this->withinLimit($fromId)) {
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

    private function withinLimit(int $fromId): bool
    {
        return RateLimiter::attempt(
            'bot-link:'.$fromId,
            max(1, (int) config('organizations.telegram_link.attempts_per_minute', 10)),
            static fn (): bool => true,
            60,
        );
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
