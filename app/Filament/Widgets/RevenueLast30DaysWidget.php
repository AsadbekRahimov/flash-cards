<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Billing\Services\BillingReportService;
use App\Domain\Billing\Support\PriceFormatter;
use App\Filament\Concerns\RequiresActiveAdmin;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Revenue of the last 30 days, one figure per currency (UZS and XTR are never added together):
 * succeeded charges / renewals, refunded ones taken out. Admin only (platform money).
 */
class RevenueLast30DaysWidget extends StatsOverviewWidget
{
    use RequiresActiveAdmin;

    public static function canView(): bool
    {
        return self::isActiveAdmin();
    }

    protected static ?int $sort = 9;

    protected ?string $heading = 'Revenue, last 30 days';

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        $stats = [];

        foreach (app(BillingReportService::class)->revenueLast30Days() as $currency => $row) {
            $stats[] = Stat::make("Revenue {$currency}", PriceFormatter::plain($row['net'], $currency))
                ->description("{$row['count']} payments · gross ".PriceFormatter::plain($row['gross'], $currency).' · refunded '.PriceFormatter::plain($row['refunded'], $currency))
                ->color($row['refunded'] > 0 ? 'warning' : ($row['net'] > 0 ? 'success' : 'gray'))
                ->icon('heroicon-o-banknotes');
        }

        return $stats;
    }
}
