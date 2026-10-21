<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Billing\Exceptions\DuplicatePaymentException;
use App\Domain\Billing\Exceptions\PaymentApplyException;
use App\Domain\Billing\Providers\TelegramStarsProvider;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Billing\Services\StarsRenewalStopper;
use App\Domain\Billing\Support\PriceFormatter;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Models\Learner;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * `message.refunded_payment` — Telegram returned the Stars of a payment (docs/13 §4.1): a refund
 * made by Telegram support, or the echo of our own `refundStarPayment` from the admin panel.
 *
 * PaymentApplier::applyRefund with event id `refund:<charge id>`: the charge row becomes
 * `refunded`, a `refund` row is written, the subscription ends now when the refunded charge paid
 * the running period. A charge the admin already refunded (`already_refunded`) and a re-delivered
 * update (`DuplicatePaymentException`) are acknowledged silently; a charge we never recorded is
 * an error log for support. Other failures propagate so the job retries. A subscription that was still renewing
 * is stopped at Telegram afterwards ({@see StarsRenewalStopper}, "Реализация 2A.8").
 */
final class RefundedPaymentHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly TelegramStarsProvider $stars,
        private readonly PaymentApplier $applier,
        private readonly StarsRenewalStopper $renewals,
        private readonly LocaleResolver $locales,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        return is_array($update['message']['refunded_payment'] ?? null);
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
        $chatId = (int) ($message['chat']['id'] ?? 0);

        try {
            $event = $this->stars->parseRefundedPayment($message);
        } catch (InvalidArgumentException $e) {
            Log::error('billing.stars.refunded_payment_malformed', ['update_id' => $update['update_id'] ?? null, 'error' => $e->getMessage()]);

            return;
        }

        // Ending the subscription hides whether it still renewed: ask before the refund is applied.
        $running = $this->renewals->runningFor($event->refundedProviderChargeId);

        try {
            $refund = $this->applier->applyRefund($event);
        } catch (DuplicatePaymentException) {
            return;
        } catch (PaymentApplyException $e) {
            if ($e->errorCode === PaymentApplyException::ALREADY_REFUNDED) {
                // Our own admin refund came back as a Telegram message: already in the ledger.
                return;
            }

            Log::error('billing.stars.refund_unknown_charge', ['charge_id' => $event->refundedProviderChargeId, 'update_id' => $update['update_id'] ?? null, 'reason' => $e->errorCode]);

            return;
        }

        // Telegram returned the Stars but keeps charging the subscription: stop it (a failure is logged, the refund stands).
        $this->renewals->stop($running);

        if ($chatId === 0) {
            return;
        }

        /** @var Learner|null $learner */
        $learner = $refund->subscriber_type === 'learner' ? Learner::withTrashed()->find($refund->subscriber_id) : null;
        $locale = $learner !== null ? $this->locales->forLearner($learner) : $this->locales->default();

        $this->telegram->sendMessage($chatId, __('billing.stars.refunded', ['amount' => PriceFormatter::format($refund->amount, $refund->currency, $locale)], $locale));
    }
}
