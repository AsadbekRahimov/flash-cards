<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\PlatformStatsService;
use App\Filament\Concerns\RequiresActiveAdmin;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Pro subscriptions of the platform. Admin only (the numbers are platform-wide). */
class PlatformSubscriptionsWidget extends StatsOverviewWidget
{
    use RequiresActiveAdmin;

    public static function canView(): bool
    {
        return self::isActiveAdmin();
    }

    protected static ?int $sort = 6;

    protected function getHeading(): string
    {
        return __('admin.widgets.platform_subscriptions.heading');
    }

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        $stats = app(PlatformStatsService::class)->subscriptions();

        return [
            Stat::make(__('admin.widgets.platform_subscriptions.pro_learners'), (string) $stats['pro_learners'])
                ->description(__('admin.widgets.platform_subscriptions.pro_learners_help', ['personal' => $stats['personal'], 'organizations' => $stats['organizations']]))
                ->color($stats['pro_learners'] > 0 ? 'success' : 'gray')
                ->icon('heroicon-o-star'),
            Stat::make(__('admin.widgets.platform_subscriptions.expiring'), (string) $stats['expiring'])
                ->description(__('admin.widgets.platform_subscriptions.expiring_help'))
                ->color($stats['expiring'] > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-clock'),
        ];
    }
}
