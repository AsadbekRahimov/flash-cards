<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\LearnerBillingService;
use App\Domain\Billing\Support\PriceFormatter;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\ProScreen;
use App\Domain\Telegram\Support\CallbackAcknowledger;
use App\Domain\Telegram\Support\DmLearnerResolver;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Buttons of `/pro`: `pro1:buy:<period>:<provider>`, `pro1:cancel`, `pro1:resume`.
 *
 * `callback_data` is not signed, so it is matched strictly and everything in it is checked again by
 * the billing services (the amount is the server's, never the button's). The query is answered at once;
 * a button of a message in any chat but the sender's own DM is ignored. A refused action is explained
 * to the learner by its error code (`billing.pro.errors.*`).
 */
final class ProCallbackHandler implements UpdateHandler
{
    private const FORMAT = '~^pro1:(?:buy:([a-z]{1,8}):([a-z_]{1,16})|(cancel|resume))$~';

    /** As many taps as the TWA's `twa-checkout` allows a minute. */
    private const ATTEMPTS_PER_MINUTE = 10;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly CallbackAcknowledger $acknowledger,
        private readonly DmLearnerResolver $learners,
        private readonly LocaleResolver $locales,
        private readonly LearnerBillingService $billing,
        private readonly ProScreen $screen,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        return str_starts_with((string) ($update['callback_query']['data'] ?? ''), ProScreen::PREFIX.':');
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

        $fromId = (int) ($cq['from']['id'] ?? 0);
        $message = is_array($cq['message'] ?? null) ? $cq['message'] : [];

        if (($message['chat']['type'] ?? '') !== 'private'
            || $fromId <= 0
            || (int) ($message['chat']['id'] ?? 0) !== $fromId
            || preg_match(self::FORMAT, (string) ($cq['data'] ?? ''), $m) !== 1
        ) {
            return;
        }

        $learner = $this->learners->resolve(is_array($cq['from'] ?? null) ? $cq['from'] : []);
        if ($learner === null || $learner->blocked_at !== null) {
            return;
        }

        $locale = $this->locales->forLearner($learner);

        if (! RateLimiter::attempt('bot-pro:'.$learner->id, self::ATTEMPTS_PER_MINUTE, static fn (): bool => true, 60)) {
            $this->telegram->sendMessage($fromId, __('billing.pro.rate_limited', [], $locale));

            return;
        }

        try {
            match ($m[3] ?? 'buy') {
                'cancel' => $this->cancel($learner, $fromId, $locale),
                'resume' => $this->resume($learner, $fromId, $locale),
                default => $this->buy($learner, $fromId, $locale, $m[1], $m[2]),
            };
        } catch (BillingException $e) {
            $key = "billing.pro.errors.{$e->errorCode}";

            $this->telegram->sendMessage($fromId, __(Lang::has($key, $locale) ? $key : 'billing.pro.errors.generic', [], $locale));
        }
    }

    /** @throws BillingException */
    private function buy(Learner $learner, int $chatId, string $locale, string $period, string $providerKey): void
    {
        $provider = SubscriptionProvider::tryFrom($providerKey);

        if ($provider === null || ! $provider->isPaid()) {
            return;
        }

        $checkout = $this->billing->checkout($learner, Plan::Pro, $period, $provider, CheckoutService::VIA_BOT);

        $this->telegram->sendMessage(
            $chatId,
            __('billing.pro.checkout_ready', [
                'period' => __("billing.period.{$period}", [], $locale),
                'price' => PriceFormatter::format($checkout->order->amount, $checkout->order->currency, $locale),
            ], $locale),
            replyMarkup: ['inline_keyboard' => [[['text' => __('billing.pro.pay_button', [], $locale), 'url' => $checkout->link->url]]]],
        );
    }

    /** @throws BillingException */
    private function cancel(Learner $learner, int $chatId, string $locale): void
    {
        $subscription = $this->billing->cancelAutoRenew($learner);
        $ends = $subscription->current_period_ends_at;

        $this->telegram->sendMessage($chatId, $ends === null
            ? __('billing.pro.status_pro_forever', [], $locale)
            : __('billing.pro.canceled', ['date' => $this->screen->date(CarbonImmutable::instance($ends), $learner)], $locale));
    }

    /** @throws BillingException */
    private function resume(Learner $learner, int $chatId, string $locale): void
    {
        $this->billing->resumeAutoRenew($learner);

        $this->telegram->sendMessage($chatId, __('billing.pro.resumed', [], $locale));
    }
}
