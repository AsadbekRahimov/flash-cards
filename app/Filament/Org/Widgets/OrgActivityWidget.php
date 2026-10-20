<?php

declare(strict_types=1);

namespace App\Filament\Org\Widgets;

use App\Domain\Organizations\Services\OrgOverviewService;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Activity of the last 7 days from `learner_daily_stats` (docs/13 §6.3), scoped to what the user
 * sees: the whole center for an owner, their groups for a teacher. Aggregates only.
 */
class OrgActivityWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        $user = Filament::auth()->user();
        $tenant = Filament::getTenant();

        if (! $user instanceof User || ! $tenant instanceof Organization) {
            return [];
        }

        $overview = app(OrgOverviewService::class)->summary($user, $tenant);

        return [
            Stat::make(__('org.dashboard.members'), (string) $overview->activeMembers)
                ->description(__('org.dashboard.groups_count', ['count' => $overview->groups]))
                ->icon('heroicon-o-users'),
            Stat::make(__('org.dashboard.active_7d'), (string) $overview->activeLearners7d)
                ->description(__('org.dashboard.active_7d_help'))
                ->color($overview->activeLearners7d > 0 ? 'success' : 'gray')
                ->icon('heroicon-o-bolt'),
            Stat::make(__('org.dashboard.reviews_7d'), (string) $overview->reviews7d)
                ->description(__('org.dashboard.new_words_7d', ['count' => $overview->newWords7d]))
                ->icon('heroicon-o-academic-cap'),
        ];
    }
}
