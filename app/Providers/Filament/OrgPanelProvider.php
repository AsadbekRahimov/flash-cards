<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Org\Pages\Dashboard;
use App\Filament\Org\Pages\OrgProfile;
use App\Filament\Org\Pages\OrgSettings;
use App\Filament\Org\Widgets\OrgActivityWidget;
use App\Filament\Org\Widgets\OrgSeatsWidget;
use App\Http\Controllers\Org\OrgInvoiceController;
use App\Http\Middleware\RequireOrgTwoFactor;
use App\Http\Middleware\SetOrgLocale;
use App\Models\Organization;
use App\Policies\Org\OrgPolicyResolver;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Cabinet of a learning center — `/org/<slug>/…` (docs/13 §6.3, ADR-11).
 *
 * Isolation, three lines:
 *  1. Filament tenancy: `IdentifyTenant` resolves the slug and asks `User::canAccessTenant()`
 *     (a row in `organization_users`) — otherwise 404; every resource model gets the `org_tenancy`
 *     global scope (`organization_id = tenant`) while this panel is current.
 *  2. Policies: `OrgPolicyResolver` swaps the platform policies for the tenant-aware ones of
 *     `App\Policies\Org` for this panel only; each checks the record's organization and the
 *     user's role in it.
 *  3. Queries: resources build their lists through `OrgTenantScope` (owner: everything of the
 *     tenant; teacher: own groups and their learners).
 *
 * Auth: own session guard `org` (a `/admin` login is not a cabinet login), email + password,
 * password reset through the `org` broker (24 h tokens, also used by the staff invitation), custom
 * TOTP 2FA: mandatory for owners, optional for teachers (`RequireOrgTwoFactor`).
 */
class OrgPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id(OrgPolicyResolver::PANEL_ID)
            ->path('org')
            ->authGuard('org')
            ->authPasswordBroker('org')
            ->login()
            ->passwordReset()
            ->profile(OrgProfile::class, isSimple: false)
            ->brandName('LexiFlow Pro')
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->tenant(Organization::class, slugAttribute: 'slug', ownershipRelationship: 'organization')
            ->tenantProfile(OrgSettings::class)
            ->authenticatedTenantRoutes(function (): void {
                // Printable invoice of an order — owner only, behind the tenant middleware (docs/13 §6.5).
                Route::get('/invoice/{order}', OrgInvoiceController::class)->whereNumber('order')->name('invoice');
            })
            ->discoverResources(in: app_path('Filament/Org/Resources'), for: 'App\\Filament\\Org\\Resources')
            ->discoverPages(in: app_path('Filament/Org/Pages'), for: 'App\\Filament\\Org\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                OrgSeatsWidget::class,
                OrgActivityWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                RequireOrgTwoFactor::class,
            ], isPersistent: true)
            ->tenantMiddleware([
                SetOrgLocale::class,
            ], isPersistent: true);
    }
}
