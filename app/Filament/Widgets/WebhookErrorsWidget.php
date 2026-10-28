<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Billing\Services\BillingReportService;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\Support\AdminLabels;
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

    protected function getHeading(): string
    {
        return __('admin.widgets.webhook_errors.heading');
    }

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        $errors = app(BillingReportService::class)->webhookErrorsLast24Hours();

        $byProvider = collect($errors['failed_by_provider'])
            ->map(fn (int $count, string $provider): string => AdminLabels::value('common.provider', $provider).": {$count}")
            ->implode(', ');

        return [
            Stat::make(__('admin.widgets.webhook_errors.failed_payments'), (string) $errors['failed_payments'])
                ->description($byProvider !== '' ? $byProvider : __('admin.widgets.webhook_errors.none'))
                ->color($errors['failed_payments'] > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-exclamation-triangle'),
            Stat::make(__('admin.widgets.webhook_errors.canceled_transactions'), (string) $errors['canceled_transactions'])
                ->description(__('admin.widgets.webhook_errors.canceled_transactions_help'))
                ->color($errors['canceled_transactions'] > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-x-circle'),
            Stat::make(__('admin.widgets.webhook_errors.double_discounts'), (string) $errors['double_discounts'])
                ->description(__('admin.widgets.webhook_errors.double_discounts_help'))
                ->color($errors['double_discounts'] > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-receipt-percent'),
            Stat::make(__('admin.widgets.webhook_errors.tier_mismatches'), (string) $errors['tier_mismatches'])
                ->description(__('admin.widgets.webhook_errors.tier_mismatches_help'))
                ->color($errors['tier_mismatches'] > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-arrows-right-left'),
            Stat::make(__('admin.widgets.webhook_errors.stars_double'), (string) $errors['stars_double_subscriptions'])
                ->description(__('admin.widgets.webhook_errors.stars_double_help'))
                ->color($errors['stars_double_subscriptions'] > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-square-2-stack'),
        ];
    }
}
