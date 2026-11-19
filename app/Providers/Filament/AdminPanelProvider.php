<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Support\InitialAvatarProvider;
use App\Filament\Widgets\ActivityChartWidget;
use App\Filament\Widgets\BillingFunnelWidget;
use App\Filament\Widgets\ExamsLast30DaysWidget;
use App\Filament\Widgets\ExerciseMixTableWidget;
use App\Filament\Widgets\GameActivityWidget;
use App\Filament\Widgets\HardestWordsTableWidget;
use App\Filament\Widgets\LeagueGroupsTableWidget;
use App\Filament\Widgets\OnboardingFunnelWidget;
use App\Filament\Widgets\OrgActivationTableWidget;
use App\Filament\Widgets\OrganizationSeatsTableWidget;
use App\Filament\Widgets\PlatformSubscriptionsWidget;
use App\Filament\Widgets\RevenueLast30DaysWidget;
use App\Filament\Widgets\SchedulerComparisonWidget;
use App\Filament\Widgets\TopLearnersTableWidget;
use App\Filament\Widgets\TotalLearnersWidget;
use App\Filament\Widgets\WebhookErrorsWidget;
use App\Http\Middleware\RequireTwoFactor;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // SPA with hover prefetch (docs/20 §1.14): links to pages outside the panel (the 2FA pages are plain Blade, not
            // Filament layouts) must stay full page loads.
            ->spa(hasPrefetching: true)
            ->spaUrlExceptions(['*/2fa/*'])
            ->brandName('LexiFlow Pro')
            ->defaultAvatarProvider(InitialAvatarProvider::class)
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                TotalLearnersWidget::class,
                ExamsLast30DaysWidget::class,
                ActivityChartWidget::class,
                TopLearnersTableWidget::class,
                HardestWordsTableWidget::class,
                PlatformSubscriptionsWidget::class,
                OnboardingFunnelWidget::class,
                SchedulerComparisonWidget::class,
                OrganizationSeatsTableWidget::class,
                LeagueGroupsTableWidget::class,
                RevenueLast30DaysWidget::class,
                WebhookErrorsWidget::class,
                BillingFunnelWidget::class,
                GameActivityWidget::class,
                OrgActivationTableWidget::class,
                ExerciseMixTableWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                RequireTwoFactor::class,
            ], isPersistent: true);
    }
}
