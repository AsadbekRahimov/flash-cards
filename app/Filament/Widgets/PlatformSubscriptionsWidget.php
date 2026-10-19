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

    protected ?string $heading = 'Pro subscriptions';

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        $stats = app(PlatformStatsService::class)->subscriptions();

        return [
            Stat::make('Pro learners', (string) $stats['pro_learners'])
                ->description("{$stats['personal']} own, {$stats['organizations']} sponsoring organizations")
                ->color($stats['pro_learners'] > 0 ? 'success' : 'gray')
                ->icon('heroicon-o-star'),
            Stat::make('Pro ending in 7 days', (string) $stats['expiring'])
                ->description('Own and organization subscriptions')
                ->color($stats['expiring'] > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-clock'),
        ];
    }
}
