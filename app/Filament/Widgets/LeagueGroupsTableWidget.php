<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Engagement\Services\LeagueService;
use App\Filament\Concerns\RequiresActiveAdmin;
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
                Tables\Columns\TextColumn::make('id')->label('Group'),
                Tables\Columns\TextColumn::make('tier')
                    ->label('Tier')
                    ->formatStateUsing(fn (int $state): string => ucfirst($tiers[$state] ?? (string) $state).' ('.$state.')'),
                Tables\Columns\TextColumn::make('members_count')
                    ->label('Members')
                    ->formatStateUsing(fn (int $state, LeagueGroup $record): string => "{$state} / {$record->capacity}")
                    ->color(fn (int $state, LeagueGroup $record): ?string => $state >= $record->capacity ? 'warning' : null),
                Tables\Columns\TextColumn::make('created_at')->label('Opened')->since(),
            ])
            ->emptyStateHeading('No league groups this week yet')
            ->paginated([10, 25, 50]);
    }

    private function headingFor(?LeagueWeek $week, LeagueService $leagues): string
    {
        if (! $leagues->enabled()) {
            return 'Leagues — disabled';
        }

        if ($week === null) {
            return 'Leagues — current week: no groups yet';
        }

        $groups = LeagueGroup::query()->where('league_week_id', $week->id)->count();
        $members = (int) LeagueGroup::query()->where('league_week_id', $week->id)->sum('members_count');

        return sprintf(
            'Leagues — week %s – %s (%s): %d groups, %d members',
            $week->starts_on->toDateString(),
            $week->ends_on->toDateString(),
            $week->status,
            $groups,
            $members,
        );
    }
}
