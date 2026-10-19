<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\PlatformStatsService;
use App\Filament\Concerns\RequiresActiveAdmin;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Onboarding funnel of the last 7 days from product_events. Admin only. */
class OnboardingFunnelWidget extends StatsOverviewWidget
{
    use RequiresActiveAdmin;

    public static function canView(): bool
    {
        return self::isActiveAdmin();
    }

    protected static ?int $sort = 7;

    protected ?string $heading = 'Onboarding funnel — 7 days';

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        $funnel = app(PlatformStatsService::class)->onboardingFunnel();

        return [
            Stat::make('Started onboarding', (string) $funnel['started'])
                ->icon('heroicon-o-play'),
            Stat::make('Completed', (string) $funnel['completed'])
                ->description('Of those who started in the last 7 days')
                ->color($funnel['completed'] > 0 ? 'success' : 'gray')
                ->icon('heroicon-o-check-circle'),
            Stat::make('Conversion', $funnel['conversion'] === null ? '—' : $funnel['conversion'].'%')
                ->icon('heroicon-o-funnel'),
        ];
    }
}
