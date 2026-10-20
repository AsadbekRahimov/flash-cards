<?php

declare(strict_types=1);

namespace App\Filament\Org\Widgets;

use App\Domain\Organizations\Services\OrgOverviewService;
use App\Domain\Organizations\Services\OrgTenantScope;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Seats and subscription of the center (docs/13 §6.1, §6.3): owner only — a teacher's dashboard
 * has no billing. `canView` hides it; the Livewire `mount` of a hidden widget is refused too.
 */
class OrgSeatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();
        $tenant = Filament::getTenant();

        return $user instanceof User && $tenant instanceof Organization && app(OrgTenantScope::class)->isOwner($user, $tenant);
    }

    public function mount(): void
    {
        abort_unless(static::canView(), 403);
    }

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        /** @var Organization $tenant */
        $tenant = Filament::getTenant();

        $overview = app(OrgOverviewService::class)->summary($user, $tenant);
        $seats = $overview->seats;

        $seatsValue = $seats->sponsoring ? $seats->seated.' / '.($seats->limit ?? '∞') : (string) $seats->seated;

        return [
            Stat::make(__('org.dashboard.seats'), $seatsValue)
                ->description($seats->sponsoring
                    ? ($seats->overLimit() ? __('org.dashboard.seats_over_limit') : __('org.dashboard.seats_free', ['count' => $seats->unlimited() ? '∞' : $seats->free()]))
                    : __('org.dashboard.not_sponsoring'))
                ->color($seats->overLimit() || ! $seats->sponsoring ? 'danger' : 'success')
                ->icon('heroicon-o-identification'),
            Stat::make(__('org.dashboard.waitlisted'), (string) $seats->waitlisted)
                ->description(__('org.dashboard.waitlisted_help'))
                ->color($seats->waitlisted > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-clock'),
            Stat::make(__('org.dashboard.subscription'), $overview->subscriptionTier ?? __('org.billing.none'))
                ->description($overview->subscriptionEndsAt === null
                    ? ($overview->subscriptionStatus === null ? __('org.dashboard.subscription_none') : __('org.dashboard.subscription_perpetual'))
                    : __('org.dashboard.subscription_until', ['date' => $overview->subscriptionEndsAt->format('d.m.Y')]))
                ->color($overview->subscriptionStatus === null ? 'danger' : 'primary')
                ->icon('heroicon-o-credit-card'),
        ];
    }
}
