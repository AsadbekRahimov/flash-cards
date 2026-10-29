<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Engagement\Services\LeagueService;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\Support\AdminDates;
use App\Filament\Resources\Support\AdminLabels;
use App\Models\LeagueGroup;
use App\Models\LeagueWeek;
use Carbon\CarbonImmutable;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Groups of the current league week (docs/13 §7.3): tier, members / capacity, status. The heading
 * carries the week's dates and totals. Admin only; empty until the first placement of the week.
 */
class LeagueGroupsTableWidget extends TableWidget
{
    use RequiresActiveAdmin;

    public static function canView(): bool
    {
        return self::isActiveAdmin();
    }

    protected static ?int $sort = 9;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $leagues = app(LeagueService::class);
        $week = $leagues->findWeek(CarbonImmutable::now());
        $tiers = $leagues->tiers();

        return $table
            ->heading($this->headingFor($week, $leagues))
            ->query(
                LeagueGroup::query()
                    ->where('league_week_id', $week === null ? 0 : $week->id)
                    ->orderByDesc('tier')
                    ->orderBy('id'),
            )
            ->columns([
                Tables\Columns\TextColumn::make('id')->label(__('admin.widgets.league_groups.group')),
                Tables\Columns\TextColumn::make('tier')
                    ->label(__('admin.widgets.league_groups.tier'))
                    // Tier names are the learner-facing ones (lang/ru/leagues.php), the number is the tier index.
                    ->formatStateUsing(fn (int $state): string => AdminLabels::from('leagues.tiers', $tiers[$state] ?? $state).' ('.$state.')'),
                Tables\Columns\TextColumn::make('members_count')
                    ->label(__('admin.widgets.league_groups.members'))
                    ->formatStateUsing(fn (int $state, LeagueGroup $record): string => "{$state} / {$record->capacity}")
                    ->color(fn (int $state, LeagueGroup $record): ?string => $state >= $record->capacity ? 'warning' : null),
                Tables\Columns\TextColumn::make('created_at')->label(__('admin.widgets.league_groups.opened'))->since(),
            ])
            ->emptyStateHeading(__('admin.widgets.league_groups.empty'))
            ->paginated([10, 25, 50]);
    }

    private function headingFor(?LeagueWeek $week, LeagueService $leagues): string
    {
        if (! $leagues->enabled()) {
            return __('admin.widgets.league_groups.heading_disabled');
        }

        if ($week === null) {
            return __('admin.widgets.league_groups.heading_empty');
        }

        $groups = LeagueGroup::query()->where('league_week_id', $week->id)->count();
        $members = (int) LeagueGroup::query()->where('league_week_id', $week->id)->sum('members_count');

        return __('admin.widgets.league_groups.heading', [
            'from' => AdminDates::date($week->starts_on),
            'to' => AdminDates::date($week->ends_on),
            'status' => AdminLabels::value('widgets.league_groups.week_status', $week->status),
            'groups' => $groups,
            'members' => $members,
        ]);
    }
}
