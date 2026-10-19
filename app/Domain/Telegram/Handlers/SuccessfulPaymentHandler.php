<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Billing\Exceptions\DuplicatePaymentException;
use App\Domain\Billing\Exceptions\PaymentApplyException;
use App\Domain\Billing\Providers\TelegramStarsProvider;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Models\Learner;
use App\Models\Payment;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * `message.successful_payment` — Telegram Stars money arrived (docs/13 §4.1). First in the
 * dispatcher: a payment message must never fall through to the DM catch-all.
 *
 * TelegramStarsProvider::parseSuccessfulPayment -> PaymentApplier::applyCharge, then one message
 * to the payer: "Pro until <date>" with a button into the app. Outcomes that are not a credit are
 * final (the applier has already committed a `failed` ledger row where it could) and are answered
 * with the "we are on it, /paysupport" text — the job is NOT retried for them:
 *
 *   DuplicatePaymentException   Telegram re-delivered the update: nothing to do, no second message
 *   order_not_found             the order is gone (a renewal of a deleted order): the money is
 *                               recorded as an orphan `failed` row for the learner behind `from.id`
 *                               (`billing.payment_orphan` alert), Pro is not granted automatically
 *   order_expired / mismatch    `failed` row committed by the applier; support decides
 *   amount mismatch             the applier returns the `failed` row without an exception
 *
 * Any other failure (database down, Telegram API) propagates so the queued job retries.
 */
final class SuccessfulPaymentHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly TelegramStarsProvider $stars,
        private readonly PaymentApplier $applier,
        private readonly LocaleResolver $locales,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        return is_array($update['message']['successful_payment'] ?? null);
    }

    // Arrives in the payer's private chat; the Group Lock does not apply.
    public function requiresActiveGroup(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $message */
        $message = $update['message'];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $fromId = (int) ($message['from']['id'] ?? 0);

        try {
            $event = $this->stars->parseSuccessfulPayment($message);
        } catch (InvalidArgumentException $e) {
            // Not something we can ever credit; retrying will not change the payload.
            Log::error('billing.stars.successful_payment_malformed', ['update_id' => $update['update_id'] ?? null, 'error' => $e->getMessage()]);

            return;
        }

        try {
            $payment = $this->applier->applyCharge($event);
        } catch (DuplicatePaymentException $e) {
            Log::info('billing.stars.successful_payment_repeated', ['payment_id' => $e->payment->id, 'update_id' => $update['update_id'] ?? null]);

            return;
        } catch (PaymentApplyException $e) {
            $payment = $e->errorCode === PaymentApplyException::ORDER_NOT_FOUND
                ? $this->recordOrphan($update, $fromId)
                : null;

            Log::warning('billing.stars.successful_payment_unresolved', ['reason' => $e->errorCode, 'update_id' => $update['update_id'] ?? null, 'payment_id' => $payment?->id]);
            $this->tellUnresolved($chatId, $fromId);

            return;
        }

        if ($payment->status !== Payment::STATUS_SUCCEEDED) {
            $this->tellUnresolved($chatId, $fromId);

            return;
        }

        $this->tellCredited($chatId, $payment);
    }

    /** @param array<string, mixed> $update */
    private function recordOrphan(array $update, int $fromId): ?Payment
    {
        $learner = $fromId > 0 ? Learner::withTrashed()->where('telegram_user_id', $fromId)->first() : null;

        if ($learner === null) {
            Log::error('billing.payment_orphan', ['provider' => 'telegram_stars', 'reason' => 'order_not_found_and_unknown_learner', 'from_id' => $fromId, 'update_id' => $update['update_id'] ?? null]);

            return null;
        }

        try {
            /** @var array<string, mixed> $message */
            $message = $update['message'];

            return $this->applier->recordOrphan($this->stars->parseSuccessfulPayment($message), $learner, PaymentApplyException::ORDER_NOT_FOUND);
        } catch (DuplicatePaymentException $e) {
            return $e->payment;
        }
    }

    private function tellCredited(int $chatId, Payment $payment): void
    {
        if ($chatId === 0) {
            return;
        }

        /** @var Learner|null $learner */
        $learner = Learner::withTrashed()->find($payment->subscriber_id);
        $locale = $learner !== null ? $this->locales->forLearner($learner) : $this->locales->default();
        $timezone = $learner !== null ? $learner->timezone : 'UTC';

        /** @var Subscription|null $subscription */
        $subscription = $payment->subscription_id === null ? null : Subscription::query()->find($payment->subscription_id);
        $ends = $subscription?->current_period_ends_at;

        $text = match (true) {
            $ends === null => __('billing.stars.paid_forever', [], $locale),
            $payment->kind === Payment::KIND_RENEWAL => __('billing.stars.renewed', ['date' => CarbonImmutable::instance($ends)->setTimezone($timezone)->format('d.m.Y')], $locale),
            default => __('billing.stars.paid', ['date' => CarbonImmutable::instance($ends)->setTimezone($timezone)->format('d.m.Y')], $locale),
        };

        $this->telegram->sendWebAppButton(
            chatId: $chatId,
            text: $text,
            buttonText: __('billing.stars.open_button', [], $locale),
            url: rtrim((string) config('twa.base_url'), '/').'/twa/?from=payment',
        );
    }

    private function tellUnresolved(int $chatId, int $fromId): void
    {
        if ($chatId === 0) {
            return;
        }

        $learner = $fromId > 0 ? Learner::withTrashed()->where('telegram_user_id', $fromId)->first() : null;
        $locale = $learner !== null ? $this->locales->forLearner($learner) : $this->locales->default();

        $this->telegram->sendMessage($chatId, __('billing.stars.paid_unresolved', [], $locale));
    }
}
