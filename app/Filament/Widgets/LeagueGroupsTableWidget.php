<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\AdminDashboardStats;
use App\Domain\Engagement\Services\LeagueService;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\Support\AdminDates;
use App\Filament\Resources\Support\AdminLabels;
use Carbon\CarbonImmutable;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Groups of the current league week (docs/13 §7.3): tier, members / capacity, status. The heading
 * carries the week's dates and totals. Admin only; empty until the first placement of the week.
 *
 * The heading and each page of rows come from the cached arrays of {@see AdminDashboardStats} (docs/20 §1.11, `->records()`
 * with the page the viewer asked for), so an open of the dashboard runs no query.
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
        $stats = app(AdminDashboardStats::class);
        $league = $stats->leagueWeek();
        $tiers = app(LeagueService::class)->tiers();

        return $table
            ->heading($this->headingFor($league))
            ->records(function (int|string $page, int|string $recordsPerPage) use ($stats, $league): LengthAwarePaginator {
                [$page, $perPage] = [max(1, (int) $page), max(1, (int) $recordsPerPage)];
                $weekId = $league['week']['id'] ?? null;
                $groups = $weekId === null ? ['total' => 0, 'rows' => []] : $stats->leagueGroups($weekId, $page, $perPage);

                return (new LengthAwarePaginator(array_column($groups['rows'], null, 'id'), $groups['total'], $perPage, $page))->onEachSide(0);
            })
            ->columns([
                Tables\Columns\TextColumn::make('id')->label(__('admin.widgets.league_groups.group')),
                Tables\Columns\TextColumn::make('tier')
                    ->label(__('admin.widgets.league_groups.tier'))
                    // Tier names are the learner-facing ones (lang/ru/leagues.php), the number is the tier index.
                    ->formatStateUsing(fn (int $state): string => AdminLabels::from('leagues.tiers', $tiers[$state] ?? $state).' ('.$state.')'),
                Tables\Columns\TextColumn::make('members_count')
                    ->label(__('admin.widgets.league_groups.members'))
                    ->formatStateUsing(fn (int $state, array $record): string => "{$state} / {$record['capacity']}")
                    ->color(fn (int $state, array $record): ?string => $state >= $record['capacity'] ? 'warning' : null),
                Tables\Columns\TextColumn::make('created_at')->label(__('admin.widgets.league_groups.opened'))->since(),
            ])
            ->emptyStateHeading(__('admin.widgets.league_groups.empty'))
            ->paginated([10, 25, 50]);
    }

    /** @param  array{enabled: bool, week: array{id: int, from: string, to: string, status: string, groups: int, members: int}|null}  $league */
    private function headingFor(array $league): string
    {
        if (! $league['enabled']) {
            return __('admin.widgets.league_groups.heading_disabled');
        }

        $week = $league['week'];

        if ($week === null) {
            return __('admin.widgets.league_groups.heading_empty');
        }

        return __('admin.widgets.league_groups.heading', [
            'from' => AdminDates::date(CarbonImmutable::parse($week['from'])),
            'to' => AdminDates::date(CarbonImmutable::parse($week['to'])),
            'status' => AdminLabels::value('widgets.league_groups.week_status', $week['status']),
            'groups' => $week['groups'],
            'members' => $week['members'],
        ]);
    }
}
