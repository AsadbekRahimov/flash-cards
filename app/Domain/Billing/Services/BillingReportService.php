<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\ProviderTransaction;
use Carbon\CarbonImmutable;

/**
 * Numbers for the admin dashboard widgets (docs/13 §11 2A.7). Read-only aggregates over the ledger.
 */
final class BillingReportService
{
    /** Currencies shown even when there was no money in them, in this order. */
    public const CURRENCIES = ['UZS', 'XTR'];

    /**
     * Money movement over the last 30 days per currency (never summed across currencies: tiyin and
     * Stars are different units), by the date each event happened:
     * `gross` = charge / renewal rows that occurred in the window (succeeded + refunded, whatever
     * their later fate), `refunds` = `refund` rows that occurred in the window (by the REFUND date,
     * so a refund today for a purchase 40 days ago is counted), `net` = gross - refunds (net cash
     * movement; negative when more was paid back than came in). `failed` rows never carried money.
     *
     * @return array<string, array{gross: int, refunds: int, net: int, charge_count: int, refund_count: int}>
     */
    public function cashFlowLast30Days(?CarbonImmutable $now = null): array
    {
        $since = ($now ?? CarbonImmutable::now())->subDays(30);

        $charges = Payment::query()
            ->whereIn('kind', [Payment::KIND_CHARGE, Payment::KIND_RENEWAL])
            ->whereIn('status', [Payment::STATUS_SUCCEEDED, Payment::STATUS_REFUNDED])
            ->where('occurred_at', '>=', $since)
            ->groupBy('currency')
            ->selectRaw('currency, sum(amount) as total, count(*) as cnt')
            ->get();

        $refunds = Payment::query()
            ->where('kind', Payment::KIND_REFUND)
            ->where('status', Payment::STATUS_SUCCEEDED)
            ->where('occurred_at', '>=', $since)
            ->groupBy('currency')
            ->selectRaw('currency, sum(amount) as total, count(*) as cnt')
            ->get();

        $result = array_fill_keys(self::CURRENCIES, ['gross' => 0, 'refunds' => 0, 'net' => 0, 'charge_count' => 0, 'refund_count' => 0]);

        foreach ($charges as $row) {
            $currency = (string) $row->getAttribute('currency');
            $result[$currency] ??= ['gross' => 0, 'refunds' => 0, 'net' => 0, 'charge_count' => 0, 'refund_count' => 0];
            $result[$currency]['gross'] = (int) $row->getAttribute('total');
            $result[$currency]['charge_count'] = (int) $row->getAttribute('cnt');
        }

        foreach ($refunds as $row) {
            $currency = (string) $row->getAttribute('currency');
            $result[$currency] ??= ['gross' => 0, 'refunds' => 0, 'net' => 0, 'charge_count' => 0, 'refund_count' => 0];
            $result[$currency]['refunds'] = (int) $row->getAttribute('total');
            $result[$currency]['refund_count'] = (int) $row->getAttribute('cnt');
        }

        foreach ($result as $currency => $row) {
            $result[$currency]['net'] = $row['gross'] - $row['refunds'];
        }

        return $result;
    }

    /**
     * COHORT revenue: the charges / renewals that occurred in the last 30 days and what became of THEM
     * (not cash flow: a refund today for an older purchase is not here, see `cashFlowLast30Days`).
     * `gross` = succeeded + refunded, `refunded` = those marked refunded, `net` = gross - refunded
     * (= the still `succeeded` ones). `refund` rows are the bookkeeping mirror of a `refunded`
     * charge and `failed` rows never carried money, so neither is added again.
     *
     * @return array<string, array{gross: int, refunded: int, net: int, count: int}>
     */
    public function cohortRevenueLast30Days(?CarbonImmutable $now = null): array
    {
        $since = ($now ?? CarbonImmutable::now())->subDays(30);

        $rows = Payment::query()
            ->whereIn('kind', [Payment::KIND_CHARGE, Payment::KIND_RENEWAL])
            ->whereIn('status', [Payment::STATUS_SUCCEEDED, Payment::STATUS_REFUNDED])
            ->where('occurred_at', '>=', $since)
            ->groupBy('currency')
            ->selectRaw(
                'currency, sum(amount) as gross, sum(case when status = ? then amount else 0 end) as refunded, count(*) as cnt',
                [Payment::STATUS_REFUNDED],
            )
            ->get();

        $result = array_fill_keys(self::CURRENCIES, ['gross' => 0, 'refunded' => 0, 'net' => 0, 'count' => 0]);

        foreach ($rows as $row) {
            $gross = (int) $row->getAttribute('gross');
            $refunded = (int) $row->getAttribute('refunded');
            $result[(string) $row->getAttribute('currency')] = ['gross' => $gross, 'refunded' => $refunded, 'net' => $gross - $refunded, 'count' => (int) $row->getAttribute('cnt')];
        }

        return $result;
    }

    /**
     * Webhook trouble of the last 24 hours: ledger rows recorded as `failed` (money that fits no
     * credit: amount mismatch, dead order, orphan renewal) and provider transactions canceled in
     * the window (Payme -1 / -2, Click -1), and orders paid in the window with the offer's discount
     * already used by another order (`meta.offer_double_discount`, docs/13 "Реализация 2B.7a"), and orders
     * paid in the window for a tier other than the live one (`meta.tier_mismatch`, 2B.8), and orders paid in the
     * window that opened a second Telegram Stars subscription next to a running one
     * (`meta.stars_double_subscription`, "Реализация 2A.8").
     * The JSON key is filtered in SQL (`where('meta->offer_double_discount', true)`: json_extract on
     * SQLite, jsonb on PostgreSQL), never in PHP.
     *
     * @return array{failed_payments: int, failed_by_provider: array<string, int>, canceled_transactions: int, double_discounts: int, tier_mismatches: int, stars_double_subscriptions: int}
     */
    public function webhookErrorsLast24Hours(?CarbonImmutable $now = null): array
    {
        $since = ($now ?? CarbonImmutable::now())->subDay();

        /** @var array<string, int> $byProvider */
        $byProvider = Payment::query()
            ->where('status', Payment::STATUS_FAILED)
            ->where('created_at', '>=', $since)
            ->groupBy('provider')
            ->selectRaw('provider, count(*) as cnt')
            ->pluck('cnt', 'provider')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        return [
            'failed_payments' => array_sum($byProvider),
            'failed_by_provider' => $byProvider,
            'canceled_transactions' => ProviderTransaction::query()
                ->whereIn('state', [ProviderTransaction::STATE_CANCELED, ProviderTransaction::STATE_CANCELED_AFTER_PERFORM])
                ->where('canceled_at', '>=', $since)
                ->count(),
            'double_discounts' => PaymentOrder::query()
                ->where('meta->offer_double_discount', true)
                ->where('paid_at', '>=', $since)
                ->count(),
            'tier_mismatches' => PaymentOrder::query()
                ->where('meta->tier_mismatch', true)
                ->where('paid_at', '>=', $since)
                ->count(),
            'stars_double_subscriptions' => PaymentOrder::query()
                ->where('meta->stars_double_subscription', true)
                ->where('paid_at', '>=', $since)
                ->count(),
        ];
    }
}
