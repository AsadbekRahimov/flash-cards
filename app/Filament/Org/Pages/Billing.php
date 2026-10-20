<?php

declare(strict_types=1);

namespace App\Filament\Org\Pages;

use App\Domain\Organizations\Services\OrgOverviewService;
use App\Domain\Organizations\Services\OrgTenantScope;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Billing of the center (docs/13 §6.5) — owner only. Until task 2B.6 (tariffs, Payme / Click,
 * invoice) it shows the current subscription and the seats, read-only.
 */
class Billing extends StubPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?int $navigationSort = 70;

    public static function getNavigationLabel(): string
    {
        return __('org.nav.billing');
    }

    public function getTitle(): string
    {
        return __('org.nav.billing');
    }

    /** Owner of the current tenant only — also enforced in the Livewire `mount` by Filament (`canAccess`). */
    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();
        $tenant = Filament::getTenant();

        return $user instanceof User
            && $tenant instanceof Organization
            && app(OrgTenantScope::class)->isOwner($user, $tenant);
    }

    public function message(): string
    {
        return __('org.stubs.billing');
    }

    /** @return array<string, string> */
    public function rows(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        /** @var Organization $tenant */
        $tenant = Filament::getTenant();

        $overview = app(OrgOverviewService::class)->summary($user, $tenant);
        $seats = $overview->seats;

        return [
            __('org.billing.rows.tier') => $overview->subscriptionTier ?? __('org.billing.none'),
            __('org.billing.rows.status') => $overview->subscriptionStatus === null
                ? __('org.billing.none')
                : __('org.billing.status.'.$overview->subscriptionStatus),
            __('org.billing.rows.ends_at') => $overview->subscriptionEndsAt?->format('d.m.Y') ?? '—',
            __('org.billing.rows.seats') => $seats->sponsoring
                ? $seats->seated.' / '.($seats->limit ?? '∞')
                : __('org.billing.none'),
            __('org.billing.rows.waitlisted') => (string) $seats->waitlisted,
        ];
    }
}
