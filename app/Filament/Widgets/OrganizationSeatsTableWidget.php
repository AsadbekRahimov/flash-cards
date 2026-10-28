<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Billing\Support\PaidSubscriptionQuery;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\Support\AdminLabels;
use App\Models\Organization;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\DB;

/**
 * "Seats / waiting" per organization (docs/13 §1.3, §6.1). Seated = `organization_members`
 * rows `active + seated` of a learner who is not soft-deleted; waiting = `active + waitlisted`.
 * Seats = `seats_limit` of the organization's granting subscription (empty = unlimited or no
 * subscription). Seated above the limit (a tariff downgrade) is shown as "excess", nobody is
 * unseated automatically. Admin only.
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
        $now = CarbonImmutable::now();

        $members = fn (string $seatState) => DB::table('organization_members as om')
            // A deleted learner (soft delete) is not a member any more, though the membership row stays.
            ->join('learners as l', 'l.id', '=', 'om.learner_id')
            ->whereNull('l.deleted_at')
            ->whereColumn('om.organization_id', 'organizations.id')
            ->where('om.status', 'active')
            ->where('om.seat_state', $seatState)
            ->selectRaw('count(*)');

        $sponsoring = PaidSubscriptionQuery::granting(
            Subscription::query()
                ->where('subscriptions.subscriber_type', 'organization')
                ->whereColumn('subscriptions.subscriber_id', 'organizations.id'),
            $now,
        )->orderByDesc('subscriptions.id')->limit(1);

        return $table
            ->query(
                Organization::query()
                    ->withCount(['telegramGroups as active_groups_count' => fn ($groups) => $groups->where('status', 'active')])
                    ->addSelect([
                        'members_count' => $members('seated'),
                        'waitlisted_count' => $members('waitlisted'),
                        'sponsor_subscription_id' => (clone $sponsoring)->select('subscriptions.id'),
                        'seats_limit' => (clone $sponsoring)->select('subscriptions.seats_limit'),
                    ])
                    ->orderByDesc('members_count')
                    ->orderBy('name'),
            )
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
                    ->color(fn (Organization $record): ?string => self::overLimit($record) ? 'danger' : null)
                    ->description(function (Organization $record): ?string {
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
                    ->state(fn (Organization $record): bool => $record->getAttribute('sponsor_subscription_id') !== null && $record->status === 'active')
                    ->boolean(),
            ])
            ->emptyStateHeading(__('admin.widgets.organization_seats.empty'))
            ->paginated([10, 25]);
    }

    private static function overLimit(Organization $record): bool
    {
        $limit = $record->getAttribute('seats_limit');

        return $limit !== null && (int) $record->getAttribute('members_count') > (int) $limit;
    }
}
