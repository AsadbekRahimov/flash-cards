<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\PlatformStatsService;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\Support\AdminNumbers;
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

    // No polling (docs/20 §1.11): Filament polls a stats widget every 5 s by default; the figures are cached anyway.
    protected ?string $pollingInterval = null;

    protected function getHeading(): string
    {
        return __('admin.widgets.onboarding_funnel.heading');
    }

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        $funnel = app(PlatformStatsService::class)->onboardingFunnel();

        return [
            Stat::make(__('admin.widgets.onboarding_funnel.started'), (string) $funnel['started'])
                ->icon('heroicon-o-play'),
            Stat::make(__('admin.widgets.onboarding_funnel.completed'), (string) $funnel['completed'])
                ->description(__('admin.widgets.onboarding_funnel.completed_help'))
                ->color($funnel['completed'] > 0 ? 'success' : 'gray')
                ->icon('heroicon-o-check-circle'),
            Stat::make(__('admin.widgets.onboarding_funnel.conversion'), $funnel['conversion'] === null ? '—' : AdminNumbers::decimal($funnel['conversion'], trimZeros: true).'%')
                ->icon('heroicon-o-funnel'),
        ];
    }
}
