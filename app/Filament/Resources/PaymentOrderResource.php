<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Billing\Support\OrgTiers;
use App\Domain\Billing\Support\PayloadMasker;
use App\Domain\Billing\Support\PriceFormatter;
use App\Filament\Resources\PaymentOrderResource\Pages;
use App\Filament\Resources\Support\AdminLabels;
use App\Filament\Resources\Support\BillingDisplay;
use App\Models\PaymentOrder;
use Filament\Actions;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payment orders: the intent to buy and the only source of the amount (docs/13 §1.2). Read-only,
 * admin only (PaymentOrderPolicy). The order token doubles as the key the providers know the order
 * by, so it is shown shortened (PayloadMasker::token) and the checkout link is not shown at all.
 */
class PaymentOrderResource extends Resource
{
    protected static ?string $model = PaymentOrder::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?int $navigationSort = 50;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.billing');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.orders.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.orders.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.orders.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.orders.sections.order'))->columns(3)->schema([
                TextEntry::make('id')->label('#'),
                TextEntry::make('status')
                    ->label(__('admin.orders.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('orders.status', $state))
                    ->color(fn (string $state): string => self::statusColor($state)),
                TextEntry::make('token_label')->label(__('admin.orders.fields.token'))->state(fn (PaymentOrder $record): string => PayloadMasker::token($record->token)),
                TextEntry::make('subscriber_label')->label(__('admin.orders.fields.subscriber'))->state(fn (PaymentOrder $record): string => BillingDisplay::subscriber($record->subscriber_type, $record->subscriber_id, $record->subscriber)),
                TextEntry::make('plan')
                    ->label(__('admin.orders.fields.plan'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('common.plan', $state)),
                TextEntry::make('tier')
                    ->label(__('admin.orders.fields.tier'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => AdminLabels::value('common.tier', $state))
                    ->placeholder('—'),
                TextEntry::make('period')
                    ->label(__('admin.orders.fields.period'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('common.period', $state)),
                TextEntry::make('provider')
                    ->label(__('admin.orders.fields.provider'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('common.provider', $state)),
                TextEntry::make('created_via')
                    ->label(__('admin.orders.fields.created_via'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('orders.created_via', $state)),
                TextEntry::make('amount_label')->label(__('admin.orders.fields.amount'))->state(fn (PaymentOrder $record): string => PriceFormatter::plain($record->amount, $record->currency)),
                TextEntry::make('list_amount_label')->label(__('admin.orders.fields.list_amount'))->state(fn (PaymentOrder $record): string => PriceFormatter::plain($record->list_amount, $record->currency)),
                TextEntry::make('offer_id')->label(__('admin.orders.fields.offer'))->placeholder('—'),
                TextEntry::make('created_at')->label(__('admin.orders.fields.created_at'))->dateTime(),
                TextEntry::make('expires_at')->label(__('admin.orders.fields.expires_at'))->dateTime(),
                TextEntry::make('paid_at')->label(__('admin.orders.fields.paid_at'))->dateTime()->placeholder('—'),
                TextEntry::make('subscription_id')->label(__('admin.orders.fields.subscription'))->placeholder('—'),
                TextEntry::make('canceled_reason')->label(__('admin.orders.fields.canceled_reason'))->badge()->color('gray')->placeholder('—')
                    ->state(fn (PaymentOrder $record): ?string => self::canceledReason($record))
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('orders.canceled_reason', $state)),
                TextEntry::make('paid_after_cancel')->label(__('admin.orders.fields.paid_after_cancel'))->badge()->color('warning')->placeholder('—')
                    ->state(fn (PaymentOrder $record): ?string => self::flag($record, 'paid_after_cancel')),
                TextEntry::make('offer_double_discount')->label(__('admin.orders.fields.offer_double_discount'))->badge()->color('danger')->placeholder('—')
                    ->state(fn (PaymentOrder $record): ?string => self::flag($record, 'offer_double_discount')),
                TextEntry::make('tier_mismatch')->label(__('admin.orders.fields.tier_mismatch'))->badge()->color('danger')->placeholder('—')
                    ->state(fn (PaymentOrder $record): ?string => self::tierMismatch($record)),
                TextEntry::make('stars_double_subscription')->label(__('admin.orders.fields.stars_double_subscription'))->badge()->color('danger')->placeholder('—')
                    ->state(fn (PaymentOrder $record): ?string => self::flag($record, 'stars_double_subscription')),
            ]),
            Section::make(__('admin.orders.sections.meta'))->collapsed()->schema([
                TextEntry::make('meta')->label(__('admin.orders.sections.meta'))->hiddenLabel()->state(fn (PaymentOrder $record) => BillingDisplay::payload($record->meta))->html(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('subscriber'))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label(__('admin.orders.fields.created_at'))->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('subscriber_label')
                    ->label(__('admin.orders.fields.subscriber'))
                    ->state(fn (PaymentOrder $record): string => BillingDisplay::subscriber($record->subscriber_type, $record->subscriber_id, $record->subscriber)),
                Tables\Columns\TextColumn::make('tier')
                    ->label(__('admin.orders.fields.tier'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => AdminLabels::value('common.tier', $state))
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('period')
                    ->label(__('admin.orders.fields.period'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('common.period', $state))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('provider')
                    ->label(__('admin.orders.fields.provider'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('common.provider', $state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount_label')
                    ->label(__('admin.orders.fields.amount'))
                    ->state(fn (PaymentOrder $record): string => PriceFormatter::plain($record->amount, $record->currency)),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.orders.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('orders.status', $state))
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('canceled_reason')->label(__('admin.orders.fields.canceled_reason'))->badge()->color('gray')->placeholder('—')->toggleable()
                    ->state(fn (PaymentOrder $record): ?string => self::canceledReason($record))
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('orders.canceled_reason', $state)),
                Tables\Columns\TextColumn::make('paid_after_cancel')->label(__('admin.orders.fields.paid_after_cancel'))->badge()->color('warning')->placeholder('—')->toggleable()
                    ->state(fn (PaymentOrder $record): ?string => self::flag($record, 'paid_after_cancel')),
                Tables\Columns\TextColumn::make('offer_double_discount')->label(__('admin.orders.fields.offer_double_discount'))->badge()->color('danger')->placeholder('—')
                    ->state(fn (PaymentOrder $record): ?string => self::flag($record, 'offer_double_discount')),
                Tables\Columns\TextColumn::make('tier_mismatch')->label(__('admin.orders.fields.tier_mismatch'))->badge()->color('danger')->placeholder('—')
                    ->state(fn (PaymentOrder $record): ?string => self::tierMismatch($record)),
                Tables\Columns\TextColumn::make('stars_double_subscription')->label(__('admin.orders.fields.stars_double_subscription'))->badge()->color('danger')->placeholder('—')
                    ->state(fn (PaymentOrder $record): ?string => self::flag($record, 'stars_double_subscription')),
                Tables\Columns\TextColumn::make('created_via')
                    ->label(__('admin.orders.fields.created_via'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('orders.created_via', $state))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('expires_at')->label(__('admin.orders.fields.expires_at'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('paid_at')->label(__('admin.orders.fields.paid_at'))->dateTime()->placeholder('—')->sortable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.orders.fields.status'))
                    ->options(AdminLabels::options('orders.status', [PaymentOrder::STATUS_PENDING, PaymentOrder::STATUS_PAID, PaymentOrder::STATUS_CANCELED, PaymentOrder::STATUS_EXPIRED, PaymentOrder::STATUS_FAILED])),
                Tables\Filters\SelectFilter::make('provider')
                    ->label(__('admin.orders.fields.provider'))
                    ->options(AdminLabels::options('common.provider', ['telegram_stars', 'payme', 'click'])),
                Tables\Filters\SelectFilter::make('currency')
                    ->label(__('admin.orders.fields.currency'))
                    ->options(['UZS' => 'UZS', 'XTR' => 'XTR']),
                Tables\Filters\SelectFilter::make('subscriber_type')
                    ->label(__('admin.orders.fields.subscriber'))
                    ->options(AdminLabels::options('common.subscriber_type', ['learner', 'organization'])),
                Tables\Filters\SelectFilter::make('tier')
                    ->label(__('admin.orders.fields.tier'))
                    ->options(AdminLabels::options('common.tier', OrgTiers::keys())),
                Tables\Filters\Filter::make('offer_double_discount')
                    ->label(__('admin.orders.filters.offer_double_discount'))
                    ->query(fn (Builder $query): Builder => $query->where('meta->offer_double_discount', true)),
                Tables\Filters\Filter::make('tier_mismatch')
                    ->label(__('admin.orders.filters.tier_mismatch'))
                    ->query(fn (Builder $query): Builder => $query->where('meta->tier_mismatch', true)),
                Tables\Filters\Filter::make('stars_double_subscription')
                    ->label(__('admin.orders.filters.stars_double_subscription'))
                    ->query(fn (Builder $query): Builder => $query->where('meta->stars_double_subscription', true)),
            ])
            ->emptyStateHeading(__('admin.orders.empty'))
            ->actions([Actions\ViewAction::make()])
            ->defaultSort('id', 'desc');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentOrders::route('/'),
            'view' => Pages\ViewPaymentOrder::route('/{record}'),
        ];
    }

    /** `meta.canceled_reason` (offer bookkeeping, docs/13 "Реализация 2B.7a"; `tier_changed`, "Реализация 2B.8"), for canceled orders only. */
    private static function canceledReason(PaymentOrder $order): ?string
    {
        $reason = $order->meta['canceled_reason'] ?? null;

        return is_string($reason) ? $reason : null;
    }

    /** `meta.tier_mismatch` (docs/13 "Реализация 2B.8"): "тариф не совпал (действует: Репетитор)". */
    private static function tierMismatch(PaymentOrder $order): ?string
    {
        if (self::flag($order, 'tier_mismatch') === null) {
            return null;
        }

        $live = $order->meta['tier_mismatch_live_tier'] ?? null;

        return is_string($live)
            ? __('admin.orders.flags.tier_mismatch_live', ['tier' => AdminLabels::value('common.tier', $live)])
            : __('admin.orders.flags.tier_mismatch');
    }

    /** The caption of a boolean `meta` flag (`admin.orders.flags.<key>`) when it is set. */
    private static function flag(PaymentOrder $order, string $key): ?string
    {
        if (($order->meta[$key] ?? false) !== true) {
            return null;
        }

        return __('admin.orders.flags.'.$key);
    }

    private static function statusColor(string $state): string
    {
        return match ($state) {
            PaymentOrder::STATUS_PAID => 'success',
            PaymentOrder::STATUS_PENDING => 'warning',
            PaymentOrder::STATUS_FAILED => 'danger',
            default => 'gray',
        };
    }
}
