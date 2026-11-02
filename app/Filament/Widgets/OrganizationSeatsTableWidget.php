<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\AdminDashboardStats;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\Support\AdminLabels;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * "Seats / waiting" per organization (docs/13 §1.3, §6.1). Seated = `organization_members`
 * rows `active + seated` of a learner who is not soft-deleted; waiting = `active + waitlisted`.
 * Seats = `seats_limit` of the organization's granting subscription (empty = unlimited or no
 * subscription). Seated above the limit (a tariff downgrade) is shown as "excess", nobody is
 * unseated automatically. Admin only.
 *
 * A page of rows is the cached array of {@see AdminDashboardStats} (docs/20 §1.11, `->records()`): the query is the
 * heavy part and it runs once per freshness window, not per open.
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
            ->records(function (int|string $page, int|string $recordsPerPage): LengthAwarePaginator {
                [$page, $perPage] = [max(1, (int) $page), max(1, (int) $recordsPerPage)];
                $organizations = app(AdminDashboardStats::class)->organizationSeats($page, $perPage);

                return (new LengthAwarePaginator(array_column($organizations['rows'], null, 'id'), $organizations['total'], $perPage, $page))->onEachSide(0);
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
            ->paginated([10, 25]);
    }

    /** @param  array<string, mixed>  $record */
    private static function overLimit(array $record): bool
    {
        $limit = $record['seats_limit'] ?? null;

        return $limit !== null && (int) ($record['members_count'] ?? 0) > (int) $limit;
    }
}
