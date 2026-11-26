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
use Illuminate\Pagination\Paginator;

/**
 * Groups of the current league week (docs/13 §7.3): tier, category (Free / Pro — the pool of rivals of a v2 week, docs/32 §5.1),
 * members / capacity, status. The heading carries the week's dates, rules version and totals (by category in a v2 week). Admin only;
 * empty until the first placement of the week.
 *
 * The heading and each page of rows come from the cached arrays of {@see AdminDashboardStats} (docs/20 §1.11, `->records()`
 * with the page the viewer asked for), so an open of the dashboard runs no query. The page sizes are the ones the service serves
 * and the table ENFORCES them: `tableRecordsPerPage` is a public Livewire property, and a forged size would be a key of its own.
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
            // A table widget pages in the simple mode (no COUNT): the page and one row more, which tells the paginator there is a next one.
            ->records(function (int|string $page, int|string $recordsPerPage) use ($stats, $league): Paginator {
                [$page, $perPage] = [AdminDashboardStats::pageNumber((int) $page), AdminDashboardStats::pageSize((int) $recordsPerPage, AdminDashboardStats::LEAGUE_GROUPS_PAGE_SIZES)];
                $weekId = $league['week']['id'] ?? null;
                $rows = $weekId === null ? [] : $stats->leagueGroups($weekId, $page, $perPage);

                return new Paginator(array_column($rows, null, 'id'), $perPage, $page);
            })
            ->columns([
                Tables\Columns\TextColumn::make('id')->label(__('admin.widgets.league_groups.group')),
                Tables\Columns\TextColumn::make('tier')
                    ->label(__('admin.widgets.league_groups.tier'))
                    // Tier names are the learner-facing ones (lang/ru/leagues.php), the number is the tier index.
                    ->formatStateUsing(fn (int $state): string => AdminLabels::from('leagues.tiers', $tiers[$state] ?? $state).' ('.$state.')'),
                Tables\Columns\TextColumn::make('category')
                    ->label(__('admin.widgets.league_groups.category'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('learners.score_days.category', $state))
                    ->color(fn (string $state): string => $state === 'pro' ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('members_count')
                    ->label(__('admin.widgets.league_groups.members'))
                    ->formatStateUsing(fn (int $state, array $record): string => "{$state} / {$record['capacity']}")
                    ->color(fn (int $state, array $record): ?string => $state >= $record['capacity'] ? 'warning' : null),
                Tables\Columns\TextColumn::make('created_at')->label(__('admin.widgets.league_groups.opened'))->since(),
            ])
            ->emptyStateHeading(__('admin.widgets.league_groups.empty'))
            ->paginated(AdminDashboardStats::LEAGUE_GROUPS_PAGE_SIZES)
            ->enforcePaginationPageOptions();
    }

    /** @param  array{enabled: bool, week: array{id: int, from: string, to: string, status: string, rules_version: int, groups: int, members: int, categories: array<string, array{groups: int, members: int}>}|null}  $league */
    private function headingFor(array $league): string
    {
        if (! $league['enabled']) {
            return __('admin.widgets.league_groups.heading_disabled');
        }

        $week = $league['week'];

        if ($week === null) {
            return __('admin.widgets.league_groups.heading_empty');
        }

        $heading = __('admin.widgets.league_groups.heading', [
            'from' => AdminDates::date(CarbonImmutable::parse($week['from'])),
            'to' => AdminDates::date(CarbonImmutable::parse($week['to'])),
            'status' => AdminLabels::value('widgets.league_groups.week_status', $week['status']),
            'groups' => $week['groups'],
            'members' => $week['members'],
        ]);

        // A v2 week: the two pools of rivals apart («Free: 4 группы, 90 участников · Pro: …»).
        if ($week['rules_version'] >= 2) {
            $parts = [];
            foreach (['free', 'pro'] as $category) {
                $totals = $week['categories'][$category] ?? ['groups' => 0, 'members' => 0];
                $parts[] = __('admin.widgets.league_groups.heading_category', [
                    'category' => AdminLabels::value('learners.score_days.category', $category),
                    'groups' => $totals['groups'],
                    'members' => $totals['members'],
                ]);
            }
            $heading .= ' · '.__('admin.widgets.league_groups.heading_v2', ['version' => $week['rules_version'], 'categories' => implode(', ', $parts)]);
        }

        return $heading;
    }
}
