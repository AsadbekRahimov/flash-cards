<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Enums\AccountLinkResult;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\OnboardingMessenger;
use App\Domain\Telegram\Services\TelegramAccountLinkService;
use App\Domain\Telegram\Services\TelegramLinkTokens;
use App\Domain\Telegram\Support\CallbackAcknowledger;

/**
 * The buttons of the Telegram connection (docs/13 §6.2, review W2 of the audit 2026-11-10):
 *
 *   lnk1:yes:<token>  — "Yes, connect" under the question `/start link_<token>` asked: the token is spent and the account connected
 *                       ONLY when the tapper is the account the question was asked (`telegram_link_tokens.prompted_telegram_user_id`);
 *   lnk1:no:<token>   — "No": nothing is connected, the token stays live;
 *   lnk1:unlinkyes    — "Yes, disconnect" under the `/unlink` question: the tapper's Telegram leaves whichever staff user holds it;
 *   lnk1:unlinkno     — "No".
 *
 * `callback_data` is not signed, so it is matched strictly; a tap in any chat but the tapper's own DM is ignored. Answered in the
 * tapper's Telegram language (they are staff, not a learner).
 */
final class AccountLinkCallbackHandler implements UpdateHandler
{
    public const PREFIX = 'lnk1';

    private const FORMAT = '~^lnk1:(?:(yes|no):('.TelegramLinkTokens::TOKEN_PATTERN.')|(unlinkyes|unlinkno))$~';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly CallbackAcknowledger $acknowledger,
        private readonly OnboardingMessenger $keyboards,
        private readonly TelegramAccountLinkService $accountLinks,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        return str_starts_with((string) ($update['callback_query']['data'] ?? ''), self::PREFIX.':');
    }

    // Private-chat only; the Group Lock does not apply.
    public function requiresActiveGroup(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $cq */
        $cq = $update['callback_query'];

        $this->acknowledger->acknowledge($update);

        /** @var array<string, mixed> $from */
        $from = is_array($cq['from'] ?? null) ? $cq['from'] : [];
        $fromId = (int) ($from['id'] ?? 0);
        $message = is_array($cq['message'] ?? null) ? $cq['message'] : [];
        $messageId = (int) ($message['message_id'] ?? 0);

        if (($message['chat']['type'] ?? '') !== 'private'
            || $fromId <= 0
            || (int) ($message['chat']['id'] ?? 0) !== $fromId
            || preg_match(self::FORMAT, (string) ($cq['data'] ?? ''), $m) !== 1
        ) {
            return;
        }

        $languageCode = $from['language_code'] ?? null;
        $locale = $this->locales->fromLanguageCode(is_string($languageCode) ? $languageCode : null);
        $this->keyboards->clearKeyboard($fromId, $messageId);

        $action = $m[1] !== '' ? $m[1] : $m[3];

        match ($action) {
            'yes' => $this->connect($m[2], $from, $fromId, $locale),
            'no' => $this->refuse($m[2], $from, $fromId, $locale),
            'unlinkyes' => $this->disconnect($fromId, $locale),
            default => $this->telegram->sendMessage($fromId, __('bot.link.unlink_cancelled', [], $locale)),
        };
    }

    /** `n***@e***.com` — enough to recognise one's own account, not enough to learn somebody else's. */
    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        $local = mb_substr($email, 0, $at);
        $domain = mb_substr($email, $at + 1);
        $dot = strrpos($domain, '.');
        $domainMasked = $dot === false || $dot === 0
            ? mb_substr($domain, 0, 1).'***'
            : mb_substr($domain, 0, 1).'***'.mb_substr($domain, $dot);

        return mb_substr($local, 0, 1).'***@'.$domainMasked;
    }

    /** @param array<string, mixed> $from */
    private function connect(string $token, array $from, int $chatId, string $locale): void
    {
        $outcome = $this->accountLinks->confirm($token, $from);

        $text = match ($outcome->result) {
            AccountLinkResult::Linked, AccountLinkResult::AlreadyLinked => __('bot.link.account_linked', ['name' => $outcome->user->name ?? ''], $locale),
            AccountLinkResult::TelegramTaken => __('bot.link.account_taken', [], $locale),
            default => __('bot.link.account_refused', [], $locale),
        };

        $this->telegram->sendMessage($chatId, $text);
    }

    /** @param array<string, mixed> $from */
    private function refuse(string $token, array $from, int $chatId, string $locale): void
    {
        $this->accountLinks->decline($token, $from);

        $this->telegram->sendMessage($chatId, __('bot.link.account_declined', [], $locale));
    }

    private function disconnect(int $chatId, string $locale): void
    {
        $user = $this->accountLinks->unlinkByTelegram($chatId);

        $this->telegram->sendMessage(
            $chatId,
            $user === null ? __('bot.link.unlink_none', [], $locale) : __('bot.link.unlink_done', ['name' => $user->name], $locale),
        );
    }
}
