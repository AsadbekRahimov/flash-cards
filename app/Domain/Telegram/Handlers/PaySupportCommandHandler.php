<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Site\Services\SiteLinks;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Models\Learner;
use App\Models\TelegramGroup;

/**
 * `/paysupport` — where to turn with a payment problem. Telegram requires it from every bot that
 * sells with Stars (docs/13 §4.1). Plain text: the support contact (`BILLING_SUPPORT_CONTACT`), the
 * refund rules live in the public offer. Answers in a DM and in an active group (Group Lock).
 */
final class PaySupportCommandHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly SiteLinks $links,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $message = $update['message'] ?? null;
        if (! is_array($message)) {
            return false;
        }

        return preg_match('~^/paysupport(?:@\w+)?(\s|$)~', (string) ($message['text'] ?? '')) === 1;
    }

    // In a DM the Group Lock does not apply; a non-active group stays silent.
    public function requiresActiveGroup(): bool
    {
        return true;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $message */
        $message = $update['message'];
        $chatId = (int) $message['chat']['id'];

        $locale = $this->localeOf($message, $chatId);
        $contact = $this->links->supportContact();
        $offer = $this->links->pageUrl('offer', $locale);

        $this->telegram->sendMessage($chatId, $contact === null
            ? __('billing.paysupport.without_contact', ['offer' => $offer], $locale)
            : __('billing.paysupport.with_contact', ['contact' => $contact['label'], 'offer' => $offer], $locale));
    }

    /** @param array<string, mixed> $message */
    private function localeOf(array $message, int $chatId): string
    {
        if (($message['chat']['type'] ?? '') !== 'private') {
            $group = TelegramGroup::query()->where('chat_id', $chatId)->first();

            return $group !== null ? $this->locales->forGroup($group) : $this->locales->default();
        }

        $fromId = (int) ($message['from']['id'] ?? 0);
        $learner = $fromId > 0 ? Learner::query()->where('telegram_user_id', $fromId)->first() : null;
        if ($learner !== null) {
            return $this->locales->forLearner($learner);
        }

        $languageCode = $message['from']['language_code'] ?? null;

        return $this->locales->fromLanguageCode(is_string($languageCode) ? $languageCode : null);
    }
}
