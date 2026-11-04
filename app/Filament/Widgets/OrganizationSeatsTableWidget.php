<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\AdminDashboardStats;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\Support\AdminLabels;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\Paginator;

/**
 * "Seats / waiting" per organization (docs/13 §1.3, §6.1). Seated = `organization_members`
 * rows `active + seated` of a learner who is not soft-deleted; waiting = `active + waitlisted`.
 * Seats = `seats_limit` of the organization's granting subscription (empty = unlimited or no
 * subscription). Seated above the limit (a tariff downgrade) is shown as "excess", nobody is
 * unseated automatically. Admin only.
 *
 * A page of rows is the cached array of {@see AdminDashboardStats} (docs/20 §1.11, `->records()`): the query is the
 * heavy part and it runs once per freshness window, not per open. The page sizes are the ones the service serves and the table
 * ENFORCES them: `tableRecordsPerPage` is a public Livewire property, and a forged size would be a key of its own.
 */
class OrganizationSeatsTableWidget extends TableWidget
{
    use RequiresActiveAdmin;

    public static function canView(): bool
    {
        return self::isActiveAdmin();
    }

    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): string
    {
        return __('admin.widgets.organization_seats.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            // A table widget pages in the simple mode (no COUNT): the page and one row more, which tells the paginator there is a next one.
            ->records(function (int|string $page, int|string $recordsPerPage): Paginator {
                [$page, $perPage] = [AdminDashboardStats::pageNumber((int) $page), AdminDashboardStats::pageSize((int) $recordsPerPage, AdminDashboardStats::ORGANIZATION_SEATS_PAGE_SIZES)];
                $organizations = app(AdminDashboardStats::class)->organizationSeats($page, $perPage);

                return new Paginator(array_column($organizations, null, 'id'), $perPage, $page);
            })
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('admin.widgets.organization_seats.name')),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.widgets.organization_seats.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('organizations.status', $state))
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('active_groups_count')->label(__('admin.widgets.organization_seats.active_groups')),
                Tables\Columns\TextColumn::make('members_count')
                    ->label(__('admin.widgets.organization_seats.seated'))
                    ->color(fn (array $record): ?string => self::overLimit($record) ? 'danger' : null)
                    ->description(function (array $record): ?string {
                        if (! self::overLimit($record)) {
                            return null;
                        }

                        return __('admin.widgets.organization_seats.excess');
                    }),
                Tables\Columns\TextColumn::make('waitlisted_count')
                    ->label(__('admin.widgets.organization_seats.waiting'))
                    ->color(fn (int|string|null $state): ?string => (int) $state > 0 ? 'warning' : null),
                Tables\Columns\TextColumn::make('seats_limit')
                    ->label(__('admin.widgets.organization_seats.seats'))
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('sponsoring')
                    ->label(__('admin.widgets.organization_seats.sponsoring'))
                    ->boolean(),
            ])
            ->emptyStateHeading(__('admin.widgets.organization_seats.empty'))
            ->paginated(AdminDashboardStats::ORGANIZATION_SEATS_PAGE_SIZES)
            ->enforcePaginationPageOptions();
    }

    /** @param  array<string, mixed>  $record */
    private static function overLimit(array $record): bool
    {
        $limit = $record['seats_limit'] ?? null;

        return $limit !== null && (int) ($record['members_count'] ?? 0) > (int) $limit;
    }
}
