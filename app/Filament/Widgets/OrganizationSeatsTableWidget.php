<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Billing\Support\PaidSubscriptionQuery;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Models\Organization;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\DB;

/**
 * "Members / seats" per organization. Members = distinct learners with an ACTIVE membership in an
 * ACTIVE group of the organization (membership is derived, there is no organization_members table in
 * Phase 1, docs/12 §1.8). Seats = `seats_limit` of the organization's granting subscription; the
 * limit is not enforced in Phase 1 and is empty for subscriptions granted from the admin panel.
 * Admin only.
 */
class OrganizationSeatsTableWidget extends TableWidget
{
    use RequiresActiveAdmin;

    public static function canView(): bool
    {
        return self::isActiveAdmin();
    }

    protected static ?string $heading = 'Organizations — members / seats';

    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $now = CarbonImmutable::now();

        $members = DB::table('group_members as gm')
            ->join('telegram_groups as g', 'g.id', '=', 'gm.telegram_group_id')
            ->whereColumn('g.organization_id', 'organizations.id')
            ->where('g.status', 'active')
            ->where('gm.status', 'active')
            ->selectRaw('count(distinct gm.learner_id)');

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
                        'members_count' => $members,
                        'sponsor_subscription_id' => (clone $sponsoring)->select('subscriptions.id'),
                        'seats_limit' => (clone $sponsoring)->select('subscriptions.seats_limit'),
                    ])
                    ->orderByDesc('members_count')
                    ->orderBy('name'),
            )
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('active_groups_count')->label('Active groups'),
                Tables\Columns\TextColumn::make('members_count')->label('Members'),
                Tables\Columns\TextColumn::make('seats_limit')
                    ->label('Seats')
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('sponsoring')
                    ->label('Sponsoring Pro')
                    ->state(fn (Organization $record): bool => $record->getAttribute('sponsor_subscription_id') !== null && $record->status === 'active')
                    ->boolean(),
            ])
            ->paginated([10, 25]);
    }
}
