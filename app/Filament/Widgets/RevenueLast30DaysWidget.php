<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Billing\Services\BillingReportService;
use App\Domain\Billing\Support\PriceFormatter;
use App\Filament\Concerns\RequiresActiveAdmin;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Money of the last 30 days, one figure per currency (UZS and XTR are never added together): the headline
 * is the NET CASH MOVEMENT (charges by their date minus refunds by THEIR date); the description adds the
 * two parts and the cohort net (the period's own charges less what was refunded of them). Admin only.
 */
class RevenueLast30DaysWidget extends StatsOverviewWidget
{
    use RequiresActiveAdmin;

    public static function canView(): bool
    {
        return self::isActiveAdmin();
    }

    protected static ?int $sort = 9;

    protected ?string $heading = null;

    protected function getHeading(): ?string
    {
        $heading = __('billing.report.heading');

        return is_string($heading) ? $heading : null;
    }

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        $report = app(BillingReportService::class);
        $cohort = $report->cohortRevenueLast30Days();
        $stats = [];

        foreach ($report->cashFlowLast30Days() as $currency => $row) {
            $label = __('billing.report.net_cash', ['currency' => $currency]);
            $description = __('billing.report.description', [
                'charges' => $row['charge_count'],
                'gross' => PriceFormatter::plain($row['gross'], $currency),
                'refunds' => PriceFormatter::plain($row['refunds'], $currency),
                'refund_count' => $row['refund_count'],
                'cohort' => PriceFormatter::plain($cohort[$currency]['net'] ?? 0, $currency),
            ]);

            $stats[] = Stat::make(is_string($label) ? $label : $currency, PriceFormatter::plain($row['net'], $currency))
                ->description(is_string($description) ? $description : '')
                ->color($row['net'] < 0 ? 'danger' : ($row['refunds'] > 0 ? 'warning' : ($row['net'] > 0 ? 'success' : 'gray')))
                ->icon('heroicon-o-banknotes');
        }

        return $stats;
    }
}
