<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\PromoCodeService;
use App\Domain\Learners\Services\LearnerRegistrar;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Models\TelegramGroup;
use Illuminate\Support\Facades\RateLimiter;

/**
 * `/promo CODE` — redeem a promo code (docs/12 §5.5). A learner's own action, so it
 * works in a DM only; in an active group the bot just points to the DM (a code typed
 * there is visible to everybody) and a non-active group stays silent (Group Lock).
 */
final class PromoCommandHandler implements UpdateHandler
{
    /** Same cap as the TWA endpoint (`twa-promo`): code guessing is throttled per Telegram user. */
    private const ATTEMPTS_PER_MINUTE = 5;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly LearnerRegistrar $registrar,
        private readonly PromoCodeService $promos,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $message = $update['message'] ?? null;
        if (! is_array($message)) {
            return false;
        }

        return preg_match('~^/promo(?:@\w+)?(\s|$)~', (string) ($message['text'] ?? '')) === 1;
    }

    // In a DM the Group Lock does not apply; in a group only whitelisted groups get the hint.
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

        if (($message['chat']['type'] ?? '') !== 'private') {
            $group = TelegramGroup::query()->where('chat_id', $chatId)->first();
            $locale = $group !== null ? $this->locales->forGroup($group) : $this->locales->default();
            $this->telegram->sendMessage($chatId, __('bot.promo.use_private', [], $locale));

            return;
        }

        /** @var array<string, mixed> $from */
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        if ((int) ($from['id'] ?? 0) <= 0) {
            return;
        }

        $learner = $this->registrar->fromTelegramUser($from);
        $locale = $this->locales->forLearner($learner);

        if ($learner->blocked_at !== null) {
            $this->telegram->sendMessage($chatId, __('bot.promo.blocked', [], $locale));

            return;
        }

        $code = trim((string) preg_replace('~^/promo(?:@\w+)?~', '', (string) ($message['text'] ?? '')));
        if ($code === '') {
            $this->telegram->sendMessage($chatId, __('bot.promo.usage', [], $locale));

            return;
        }

        if (! RateLimiter::attempt('bot-promo:'.$learner->id, self::ATTEMPTS_PER_MINUTE, static fn (): bool => true, 60)) {
            $this->telegram->sendMessage($chatId, __('bot.promo.rate_limited', [], $locale));

            return;
        }

        try {
            $subscription = $this->promos->redeem($learner, $code);
        } catch (BillingException $e) {
            $this->telegram->sendMessage($chatId, __('bot.promo.errors.'.$e->errorCode, [], $locale));

            return;
        }

        $until = $subscription->current_period_ends_at;
        $this->telegram->sendMessage($chatId, $until === null
            ? __('bot.promo.success_forever', [], $locale)
            : __('bot.promo.success_until', ['date' => $until->setTimezone($learner->timezone)->format('d.m.Y')], $locale));
    }
}
