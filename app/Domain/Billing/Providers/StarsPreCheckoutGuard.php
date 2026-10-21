<?php

declare(strict_types=1);

namespace App\Domain\Billing\Providers;

use App\Domain\Billing\DTO\PreCheckoutVerdict;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Services\BillingProviderRegistry;
use App\Domain\Billing\Support\AutoRenewal;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\PaymentOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Decides a Stars `pre_checkout_query` (docs/13 §2.2, §4.1) — called synchronously from the
 * webhook request, so it reads ONE row (the order joined with its learner) and writes nothing.
 *
 * Refusal reasons (`PreCheckoutVerdict::reason`, text `billing.stars.precheckout.<reason>` on the
 * learner's locale, else the payer's Telegram language):
 *   order_not_found     unknown / malformed `invoice_payload`, or an order of another provider
 *   foreign             `from.id` is not the learner who created the order
 *   blocked             the learner is blocked (or deleted)
 *   order_closed        the order is already paid / canceled / failed
 *   order_expired       `expires_at` passed (the scheduler may not have flipped the status yet)
 *   amount_mismatch     `total_amount` / `currency` differ from the order
 *   provider_disabled   Stars switched off in config between checkout and payment
 *   already_subscribed  the learner's Stars subscription renews (a second one would double-charge them) — the only
 *                       second read, made after every other check has passed ("Реализация 2A.8")
 */
final class StarsPreCheckoutGuard
{
    public const REASON_ORDER_NOT_FOUND = 'order_not_found';

    public const REASON_FOREIGN = 'foreign';

    public const REASON_BLOCKED = 'blocked';

    public const REASON_ORDER_CLOSED = 'order_closed';

    public const REASON_ORDER_EXPIRED = 'order_expired';

    public const REASON_AMOUNT_MISMATCH = 'amount_mismatch';

    public const REASON_PROVIDER_DISABLED = 'provider_disabled';

    public const REASON_ALREADY_SUBSCRIBED = 'already_subscribed';

    public function __construct(
        private readonly BillingProviderRegistry $providers,
        private readonly LocaleResolver $locales,
    ) {}

    /**
     * @param  array<string, mixed>  $preCheckoutQuery  Telegram `PreCheckoutQuery`
     */
    public function check(array $preCheckoutQuery, ?CarbonImmutable $now = null): PreCheckoutVerdict
    {
        $now ??= CarbonImmutable::now();

        $from = is_array($preCheckoutQuery['from'] ?? null) ? $preCheckoutQuery['from'] : [];
        $fromId = (int) ($from['id'] ?? 0);
        $languageCode = isset($from['language_code']) && is_string($from['language_code']) ? $from['language_code'] : null;
        $payload = $preCheckoutQuery['invoice_payload'] ?? null;
        $payerLocale = $this->locales->fromLanguageCode($languageCode);

        if (! is_string($payload) || $payload === '' || strlen($payload) > 32) {
            return $this->refuse(self::REASON_ORDER_NOT_FOUND, $payerLocale, null, $fromId);
        }

        $row = DB::table('payment_orders')
            ->leftJoin('learners', 'learners.id', '=', 'payment_orders.subscriber_id')
            ->where('payment_orders.token', $payload)
            ->where('payment_orders.subscriber_type', 'learner')
            ->first([
                'payment_orders.id',
                'payment_orders.subscriber_id as learner_id',
                'payment_orders.provider',
                'payment_orders.status',
                'payment_orders.amount',
                'payment_orders.currency',
                'payment_orders.expires_at',
                'learners.telegram_user_id as learner_telegram_user_id',
                'learners.interface_locale as learner_locale',
                'learners.blocked_at as learner_blocked_at',
                'learners.deleted_at as learner_deleted_at',
            ]);

        if ($row === null || $row->provider !== SubscriptionProvider::TelegramStars->value) {
            return $this->refuse(self::REASON_ORDER_NOT_FOUND, $payerLocale, null, $fromId);
        }

        $orderId = (int) $row->id;
        $learnerTelegramId = is_numeric($row->learner_telegram_user_id) ? (int) $row->learner_telegram_user_id : 0;

        if ($learnerTelegramId <= 0 || $fromId <= 0 || $learnerTelegramId !== $fromId) {
            // Someone else holds this invoice link: do not reveal anything about the order.
            return $this->refuse(self::REASON_FOREIGN, $payerLocale, $orderId, $fromId);
        }

        $locale = is_string($row->learner_locale) && $this->locales->isSupported($row->learner_locale) ? $row->learner_locale : $payerLocale;

        if ($row->learner_blocked_at !== null || $row->learner_deleted_at !== null) {
            return $this->refuse(self::REASON_BLOCKED, $locale, $orderId, $fromId);
        }

        if ($row->status !== PaymentOrder::STATUS_PENDING) {
            return $this->refuse(self::REASON_ORDER_CLOSED, $locale, $orderId, $fromId);
        }

        if (! CarbonImmutable::parse((string) $row->expires_at, 'UTC')->greaterThan($now)) {
            return $this->refuse(self::REASON_ORDER_EXPIRED, $locale, $orderId, $fromId);
        }

        if ((int) ($preCheckoutQuery['total_amount'] ?? -1) !== (int) $row->amount || ($preCheckoutQuery['currency'] ?? null) !== $row->currency) {
            return $this->refuse(self::REASON_AMOUNT_MISMATCH, $locale, $orderId, $fromId);
        }

        if (! $this->providers->isEnabled(SubscriptionProvider::TelegramStars)) {
            return $this->refuse(self::REASON_PROVIDER_DISABLED, $locale, $orderId, $fromId);
        }

        if (AutoRenewal::forLearner((int) $row->learner_id, $now) !== null) {
            return $this->refuse(self::REASON_ALREADY_SUBSCRIBED, $locale, $orderId, $fromId);
        }

        return PreCheckoutVerdict::ok($orderId);
    }

    private function refuse(string $reason, string $locale, ?int $orderId, int $fromId): PreCheckoutVerdict
    {
        Log::warning('billing.stars.pre_checkout_refused', ['reason' => $reason, 'payment_order_id' => $orderId, 'from_id' => $fromId]);

        return PreCheckoutVerdict::refuse($reason, (string) __("billing.stars.precheckout.{$reason}", [], $locale), $orderId);
    }
}
