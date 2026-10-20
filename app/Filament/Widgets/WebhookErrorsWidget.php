<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Billing\Services\BillingReportService;
use App\Filament\Concerns\RequiresActiveAdmin;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Payment webhook trouble of the last 24 hours: events recorded as `failed` (money that could not
 * be credited) and canceled provider transactions. Admin only.
 */
class WebhookErrorsWidget extends StatsOverviewWidget
{
    use RequiresActiveAdmin;

    public static function canView(): bool
    {
        return self::isActiveAdmin();
    }

    protected static ?int $sort = 10;

    protected ?string $heading = 'Payment webhook errors, last 24 h';

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        $errors = app(BillingReportService::class)->webhookErrorsLast24Hours();

        $byProvider = collect($errors['failed_by_provider'])
            ->map(fn (int $count, string $provider): string => "{$provider}: {$count}")
            ->implode(', ');

        return [
            Stat::make('Failed payments', (string) $errors['failed_payments'])
                ->description($byProvider !== '' ? $byProvider : 'None')
                ->color($errors['failed_payments'] > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-exclamation-triangle'),
            Stat::make('Canceled provider transactions', (string) $errors['canceled_transactions'])
                ->description('Payme / Click')
                ->color($errors['canceled_transactions'] > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-x-circle'),
            Stat::make('Double discounts', (string) $errors['double_discounts'])
                ->description('Offer discount paid twice (filter on Payment orders)')
                ->color($errors['double_discounts'] > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-receipt-percent'),
            Stat::make('Tier mismatches', (string) $errors['tier_mismatches'])
                ->description('Paid for a tier other than the live one (filter on Payment orders)')
                ->color($errors['tier_mismatches'] > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-arrows-right-left'),
        ];
    }
}
