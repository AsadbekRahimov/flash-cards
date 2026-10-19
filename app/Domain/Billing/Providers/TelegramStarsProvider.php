<?php

declare(strict_types=1);

namespace App\Domain\Billing\Providers;

use App\Domain\Billing\Contracts\BillingProvider;
use App\Domain\Billing\DTO\ChargeEvent;
use App\Domain\Billing\DTO\CheckoutLink;
use App\Domain\Billing\DTO\RefundEvent;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\ProviderCapabilityException;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Models\Learner;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Telegram Stars (docs/13 §2.2, §4.1): the only auto-renewing provider. Learners only, currency
 * `XTR`, amounts are whole Stars.
 *
 *   createCheckout   createInvoiceLink with `payload = order.token`; `period = month` is a Stars
 *                    subscription (`subscription_period` 30 days), `year` a one-off invoice.
 *   parse*           the single place that knows the shape of `successful_payment` /
 *                    `refunded_payment` (field names: docs/13 §15 — verify on the staging bot).
 *   cancel / resume  editUserStarSubscription with the charge id of the subscription's FIRST
 *                    payment (`subscriptions.meta.stars.charge_id`).
 *   refund           refundStarPayment for one charge.
 *
 * Every Bot API call goes through TelegramClient and is made OUTSIDE a database transaction by
 * the callers (CheckoutService, LearnerBillingService, PaymentRefundService — docs/13 §3 rule 2).
 * A failed call surfaces as TelegramApiException (a RuntimeException), which the orchestration
 * layer turns into `provider_unavailable`.
 */
final class TelegramStarsProvider implements BillingProvider
{
    /** The only `subscription_period` Telegram accepts today (30 days). */
    public const SUBSCRIPTION_PERIOD_SECONDS = 2_592_000;

    /** `payments.provider_event_id` prefix of a refund reported by Telegram (`refunded_payment`). */
    public const REFUND_EVENT_PREFIX = 'refund:';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
    ) {}

    public function key(): SubscriptionProvider
    {
        return SubscriptionProvider::TelegramStars;
    }

    public function supports(string $subscriberType, string $currency): bool
    {
        return $subscriberType === 'learner' && $currency === 'XTR';
    }

    public function createCheckout(PaymentOrder $order): CheckoutLink
    {
        if ($order->provider !== $this->key()->value || $order->currency !== 'XTR' || $order->subscriber_type !== 'learner') {
            throw new InvalidArgumentException("Order #{$order->id} is not a Telegram Stars order of a learner.");
        }

        $locale = $this->orderLocale($order);
        $period = __("billing.period.{$order->period}", [], $locale);
        $isSubscription = $order->period === 'month';

        $url = $this->telegram->createInvoiceLink(
            title: mb_substr(__('billing.stars.invoice.title', ['period' => $period], $locale), 0, 32),
            description: mb_substr(__($isSubscription ? 'billing.stars.invoice.description_month' : 'billing.stars.invoice.description_year', [], $locale), 0, 255),
            payload: $order->token,
            currency: 'XTR',
            prices: [['label' => mb_substr(__('billing.stars.invoice.label', ['period' => $period], $locale), 0, 32), 'amount' => $order->amount]],
            subscriptionPeriod: $isSubscription ? self::SUBSCRIPTION_PERIOD_SECONDS : null,
        );

        return new CheckoutLink($url, CheckoutLink::KIND_INVOICE_LINK, CarbonImmutable::instance($order->expires_at));
    }

    /**
     * `message.successful_payment` -> ChargeEvent. Idempotency key and charge id are both
     * `telegram_payment_charge_id`; `invoice_payload` is the order token; a renewal is
     * `is_recurring && !is_first_recurring`; `subscription_expiration_date` becomes the period end.
     *
     * @param  array<string, mixed>  $message  the Telegram `Message`
     *
     * @throws InvalidArgumentException when a required field is missing
     */
    public function parseSuccessfulPayment(array $message): ChargeEvent
    {
        $payment = $message['successful_payment'] ?? null;
        if (! is_array($payment)) {
            throw new InvalidArgumentException('Message has no successful_payment.');
        }

        $chargeId = self::requiredString($payment, 'telegram_payment_charge_id');
        $isRecurring = (bool) ($payment['is_recurring'] ?? false);
        $isFirst = (bool) ($payment['is_first_recurring'] ?? false);
        $expiration = $payment['subscription_expiration_date'] ?? null;

        return new ChargeEvent(
            provider: $this->key(),
            providerEventId: $chargeId,
            providerChargeId: $chargeId,
            orderToken: self::requiredString($payment, 'invoice_payload'),
            amount: (int) ($payment['total_amount'] ?? 0),
            currency: self::requiredString($payment, 'currency'),
            isRenewal: $isRecurring && ! $isFirst,
            periodEndsAt: is_numeric($expiration) ? CarbonImmutable::createFromTimestampUTC((int) $expiration) : null,
            occurredAt: self::messageDate($message),
            raw: [
                'source' => 'telegram.successful_payment',
                'telegram_payment_charge_id' => $chargeId,
                'provider_payment_charge_id' => isset($payment['provider_payment_charge_id']) ? (string) $payment['provider_payment_charge_id'] : null,
                'is_recurring' => $isRecurring,
                'is_first_recurring' => $isFirst,
                'subscription_expiration_date' => is_numeric($expiration) ? (int) $expiration : null,
                'from_id' => isset($message['from']['id']) ? (int) $message['from']['id'] : null,
                'message_id' => isset($message['message_id']) ? (int) $message['message_id'] : null,
            ],
        );
    }

    /**
     * `message.refunded_payment` (a refund made by Telegram / its support, or the echo of our own
     * refundStarPayment) -> RefundEvent. The charge being refunded is `telegram_payment_charge_id`;
     * the ledger key is prefixed so it cannot collide with the charge row.
     *
     * @param  array<string, mixed>  $message
     *
     * @throws InvalidArgumentException
     */
    public function parseRefundedPayment(array $message): RefundEvent
    {
        $refund = $message['refunded_payment'] ?? null;
        if (! is_array($refund)) {
            throw new InvalidArgumentException('Message has no refunded_payment.');
        }

        $chargeId = self::requiredString($refund, 'telegram_payment_charge_id');

        return new RefundEvent(
            provider: $this->key(),
            providerEventId: self::REFUND_EVENT_PREFIX.$chargeId,
            refundedProviderChargeId: $chargeId,
            amount: (int) ($refund['total_amount'] ?? 0),
            currency: self::requiredString($refund, 'currency'),
            occurredAt: self::messageDate($message),
            raw: [
                'source' => 'telegram.refunded_payment',
                'telegram_payment_charge_id' => $chargeId,
                'provider_payment_charge_id' => isset($refund['provider_payment_charge_id']) ? (string) $refund['provider_payment_charge_id'] : null,
                'from_id' => isset($message['from']['id']) ? (int) $message['from']['id'] : null,
                'message_id' => isset($message['message_id']) ? (int) $message['message_id'] : null,
            ],
        );
    }

    public function cancelAutoRenew(Subscription $subscription): void
    {
        [$userId, $chargeId] = $this->subscriptionHandle($subscription, 'cancelAutoRenew');

        $this->telegram->editUserStarSubscription($userId, $chargeId, isCanceled: true);
    }

    public function resumeAutoRenew(Subscription $subscription): void
    {
        [$userId, $chargeId] = $this->subscriptionHandle($subscription, 'resumeAutoRenew');

        $this->telegram->editUserStarSubscription($userId, $chargeId, isCanceled: false);
    }

    public function refund(Payment $payment): void
    {
        if ($payment->provider !== $this->key()->value) {
            throw ProviderCapabilityException::unsupported($this->key(), "refund of a {$payment->provider} payment");
        }

        $chargeId = $payment->provider_charge_id;
        if ($chargeId === null || $chargeId === '') {
            throw ProviderCapabilityException::unsupported($this->key(), 'refund without a Stars charge id');
        }

        $userId = $payment->subscriber_type === 'learner' ? $this->telegramUserId($payment->subscriber_id) : null;
        if ($userId === null) {
            throw ProviderCapabilityException::unsupported($this->key(), 'refund without a Telegram user');
        }

        $this->telegram->refundStarPayment($userId, $chargeId);
    }

    /**
     * The learner's Telegram id and the subscription's charge id for editUserStarSubscription.
     *
     * @return array{int, string}
     */
    private function subscriptionHandle(Subscription $subscription, string $capability): array
    {
        if ($subscription->provider !== $this->key()->value) {
            throw ProviderCapabilityException::unsupported($this->key(), "{$capability} of a {$subscription->provider} subscription");
        }

        $chargeId = $subscription->meta['stars']['charge_id'] ?? $subscription->provider_ref;
        if (! is_string($chargeId) || $chargeId === '') {
            throw ProviderCapabilityException::unsupported($this->key(), "{$capability} without a Stars charge id");
        }

        $userId = $subscription->subscriber_type === 'learner' ? $this->telegramUserId($subscription->subscriber_id) : null;
        if ($userId === null) {
            throw ProviderCapabilityException::unsupported($this->key(), "{$capability} without a Telegram user");
        }

        return [$userId, $chargeId];
    }

    private function telegramUserId(int $learnerId): ?int
    {
        $id = Learner::withTrashed()->whereKey($learnerId)->value('telegram_user_id');

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    private function orderLocale(PaymentOrder $order): string
    {
        $locale = $order->meta['learner_locale'] ?? null;

        if (is_string($locale) && $this->locales->isSupported($locale)) {
            return $locale;
        }

        /** @var Learner|null $learner */
        $learner = Learner::withTrashed()->find($order->subscriber_id);

        return $learner !== null ? $this->locales->forLearner($learner) : $this->locales->default();
    }

    /** @param array<string, mixed> $data */
    private static function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_scalar($value) || (string) $value === '') {
            throw new InvalidArgumentException("Telegram payment object has no {$key}.");
        }

        return (string) $value;
    }

    /** @param array<string, mixed> $message */
    private static function messageDate(array $message): CarbonImmutable
    {
        $date = $message['date'] ?? null;

        return is_numeric($date) && (int) $date > 0 ? CarbonImmutable::createFromTimestampUTC((int) $date) : CarbonImmutable::now();
    }
}
