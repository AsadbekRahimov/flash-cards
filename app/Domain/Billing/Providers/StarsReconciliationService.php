<?php

declare(strict_types=1);

namespace App\Domain\Billing\Providers;

use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Models\Learner;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * `billing:reconcile-stars` (docs/13 §4.1, §4.4): compares Telegram's own Stars ledger
 * (`getStarTransactions`, newest first) with `payments` for the last N days and ALERTS on
 * differences — it never writes, so a repeated or overlapping run is harmless.
 *
 *   incoming transaction (source.type = user, i.e. a learner paid)  ->  a `payments` row with
 *       provider_event_id = transaction id must exist (`billing.reconcile_missing` otherwise:
 *       the webhook update was lost — the learner paid and has no Pro);
 *   outgoing transaction to a user (receiver.type = user, a refund)  ->  a `refund` row for that
 *       learner with the same amount within ±1 day must exist (`billing.reconcile_missing_refund`:
 *       Telegram support refunded and `refunded_payment` did not reach us — Pro is still on).
 *
 * Transactions with other partners (Fragment withdrawals, Telegram Ads, ...) are not ours to check.
 * The shape of `StarTransaction` is the Bot API 7.x one (docs/13 §15 — verify on the staging bot).
 */
final class StarsReconciliationService
{
    public const PAGE_SIZE = 100;

    /** Pages are read until a transaction older than the cut-off appears, but never more than this many. */
    public const MAX_PAGES = 50;

    public function __construct(private readonly TelegramClient $telegram) {}

    /**
     * @return array{checked: int, charges_missing: int, refunds_missing: int, pages: int}
     */
    public function reconcile(int $lookbackDays = 2, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $cutoff = $now->subDays(max(1, $lookbackDays));
        $report = ['checked' => 0, 'charges_missing' => 0, 'refunds_missing' => 0, 'pages' => 0];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $transactions = $this->telegram->getStarTransactions($page * self::PAGE_SIZE, self::PAGE_SIZE);
            $report['pages']++;

            if ($transactions === []) {
                break;
            }

            $reachedCutoff = false;

            foreach ($transactions as $transaction) {
                $date = is_numeric($transaction['date'] ?? null) ? CarbonImmutable::createFromTimestampUTC((int) $transaction['date']) : null;

                if ($date === null || $date->lessThan($cutoff)) {
                    $reachedCutoff = true;

                    continue;
                }

                $report['checked']++;

                if ($this->isIncomingFromUser($transaction)) {
                    if (! $this->chargeRecorded($transaction)) {
                        $report['charges_missing']++;
                    }
                } elseif ($this->isOutgoingToUser($transaction) && ! $this->refundRecorded($transaction, $date)) {
                    $report['refunds_missing']++;
                }
            }

            if ($reachedCutoff || count($transactions) < self::PAGE_SIZE) {
                break;
            }
        }

        return $report;
    }

    /** @param array<string, mixed> $transaction */
    private function isIncomingFromUser(array $transaction): bool
    {
        return (($transaction['source']['type'] ?? null) === 'user') && ! isset($transaction['receiver']);
    }

    /** @param array<string, mixed> $transaction */
    private function isOutgoingToUser(array $transaction): bool
    {
        return (($transaction['receiver']['type'] ?? null) === 'user') && ! isset($transaction['source']);
    }

    /** @param array<string, mixed> $transaction */
    private function chargeRecorded(array $transaction): bool
    {
        $id = (string) ($transaction['id'] ?? '');

        if ($id === '') {
            return true;
        }

        $exists = Payment::query()
            ->where('provider', SubscriptionProvider::TelegramStars->value)
            ->where(fn ($q) => $q->where('provider_event_id', $id)->orWhere('provider_charge_id', $id))
            ->exists();

        if (! $exists) {
            Log::error('billing.reconcile_missing', [
                'provider' => 'telegram_stars',
                'transaction_id' => $id,
                'amount' => (int) ($transaction['amount'] ?? 0),
                'date' => $transaction['date'] ?? null,
                'telegram_user_id' => $transaction['source']['user']['id'] ?? null,
            ]);
        }

        return $exists;
    }

    /** @param array<string, mixed> $transaction */
    private function refundRecorded(array $transaction, CarbonImmutable $date): bool
    {
        $telegramUserId = (int) ($transaction['receiver']['user']['id'] ?? 0);
        $amount = (int) ($transaction['amount'] ?? 0);
        $learnerId = $telegramUserId > 0 ? Learner::withTrashed()->where('telegram_user_id', $telegramUserId)->value('id') : null;

        $exists = $learnerId !== null && Payment::query()
            ->where('provider', SubscriptionProvider::TelegramStars->value)
            ->where('kind', Payment::KIND_REFUND)
            ->where('subscriber_type', 'learner')
            ->where('subscriber_id', $learnerId)
            ->where('amount', $amount)
            ->whereBetween('occurred_at', [$date->subDay(), $date->addDay()])
            ->exists();

        if (! $exists) {
            Log::error('billing.reconcile_missing_refund', [
                'provider' => 'telegram_stars',
                'transaction_id' => $transaction['id'] ?? null,
                'amount' => $amount,
                'date' => $transaction['date'] ?? null,
                'telegram_user_id' => $telegramUserId,
                'learner_id' => $learnerId,
            ]);
        }

        return $exists;
    }
}
