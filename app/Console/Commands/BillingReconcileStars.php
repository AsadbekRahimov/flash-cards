<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Providers\StarsReconciliationService;
use App\Domain\Billing\Services\BillingProviderRegistry;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use Illuminate\Console\Command;

/**
 * Daily (docs/13 §4.4): Telegram's Stars transactions of the last N days vs `payments`. Read-only,
 * alerts through the log (`billing.reconcile_missing`, `billing.reconcile_missing_refund`, `billing.reconcile_empty`); a
 * non-zero exit when something is missing so the cron mail / monitor notices.
 */
final class BillingReconcileStars extends Command
{
    protected $signature = 'billing:reconcile-stars {--days=2 : How many days back to compare}';

    protected $description = 'Compare Telegram Stars transactions with the payments ledger and alert on differences';

    public function handle(StarsReconciliationService $reconciliation, BillingProviderRegistry $providers): int
    {
        if (! $providers->isEnabled(SubscriptionProvider::TelegramStars)) {
            $this->info('Telegram Stars is disabled: nothing to reconcile.');

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));

        try {
            $report = $reconciliation->reconcile($days);
        } catch (TelegramApiException $e) {
            $this->error('Telegram API unavailable: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Stars reconciliation (%d days): %d transactions checked over %d page(s); %d charge(s) and %d refund(s) missing from the ledger.',
            $days,
            $report['checked'],
            $report['pages'],
            $report['charges_missing'],
            $report['refunds_missing'],
        ));

        if ($report['ledger_without_telegram'] > 0) {
            $this->error(sprintf('Telegram returned no transactions for the period, but the ledger has %d successful Stars payment(s): the comparison is void.', $report['ledger_without_telegram']));
        }

        return $report['charges_missing'] + $report['refunds_missing'] + $report['ledger_without_telegram'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
