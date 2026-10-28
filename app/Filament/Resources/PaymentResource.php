<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Support\PriceFormatter;
use App\Filament\Resources\PaymentResource\Pages;
use App\Filament\Resources\PaymentResource\Support\PaymentActions;
use App\Filament\Resources\Support\AdminLabels;
use App\Filament\Resources\Support\BillingDisplay;
use App\Models\Payment;
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
 * Payments: the ledger of provider events (docs/13 §1.2 `payments`). Read-only, admin only
 * (PaymentPolicy); the one action is "Refund". The raw provider payload is shown masked
 * (PayloadMasker): no keys, signatures or tokens on the screen.
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.billing');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.payments.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.payments.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.payments.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.payments.sections.payment'))->columns(3)->schema([
                TextEntry::make('id')->label('#'),
                TextEntry::make('kind')
                    ->label(__('admin.payments.fields.kind'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('payments.kind', $state)),
                TextEntry::make('status')
                    ->label(__('admin.payments.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('payments.status', $state))
                    ->color(fn (string $state): string => self::statusColor($state)),
                TextEntry::make('provider')
                    ->label(__('admin.payments.fields.provider'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('common.provider', $state)),
                TextEntry::make('amount_label')->label(__('admin.payments.fields.amount'))->state(fn (Payment $record): string => PriceFormatter::plain($record->amount, $record->currency)),
                TextEntry::make('occurred_at')->label(__('admin.payments.fields.occurred_at'))->dateTime(),
                TextEntry::make('subscriber_label')->label(__('admin.payments.fields.subscriber'))->state(fn (Payment $record): string => BillingDisplay::subscriber($record->subscriber_type, $record->subscriber_id, $record->subscriber)),
                TextEntry::make('provider_charge_id')->label(__('admin.payments.fields.provider_charge_id'))->placeholder('—')->copyable(),
                TextEntry::make('provider_event_id')->label(__('admin.payments.fields.provider_event_id'))->copyable(),
                TextEntry::make('payment_order_id')->label(__('admin.payments.fields.order'))->placeholder('—')
                    ->url(fn (Payment $record): ?string => $record->payment_order_id !== null ? PaymentOrderResource::getUrl('view', ['record' => $record->payment_order_id]) : null),
                TextEntry::make('provider_transaction_id')->label(__('admin.payments.fields.provider_transaction'))->placeholder('—')
                    ->url(fn (Payment $record): ?string => $record->provider_transaction_id !== null ? ProviderTransactionResource::getUrl('view', ['record' => $record->provider_transaction_id]) : null),
                TextEntry::make('refunded_payment_id')->label(__('admin.payments.fields.refunded_payment'))->placeholder('—')
                    ->url(fn (Payment $record): ?string => $record->refunded_payment_id !== null ? self::getUrl('view', ['record' => $record->refunded_payment_id]) : null),
                TextEntry::make('period_starts_at')->label(__('admin.payments.fields.period_starts_at'))->dateTime()->placeholder('—'),
                TextEntry::make('period_ends_at')->label(__('admin.payments.fields.period_ends_at'))->dateTime()->placeholder('—'),
                TextEntry::make('subscription_id')->label(__('admin.payments.fields.subscription'))->placeholder('—'),
            ]),
            Section::make(__('admin.payments.sections.raw'))->collapsed()->schema([
                TextEntry::make('raw')->label(__('admin.payments.sections.raw'))->hiddenLabel()->state(fn (Payment $record) => BillingDisplay::payload($record->raw))->html(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('subscriber'))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('occurred_at')->label(__('admin.payments.fields.occurred_at'))->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('subscriber_label')
                    ->label(__('admin.payments.fields.subscriber'))
                    ->state(fn (Payment $record): string => BillingDisplay::subscriber($record->subscriber_type, $record->subscriber_id, $record->subscriber)),
                Tables\Columns\TextColumn::make('provider')
                    ->label(__('admin.payments.fields.provider'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('common.provider', $state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('kind')
                    ->label(__('admin.payments.fields.kind'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('payments.kind', $state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.payments.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('payments.status', $state))
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount_label')
                    ->label(__('admin.payments.fields.amount'))
                    ->state(fn (Payment $record): string => PriceFormatter::plain($record->amount, $record->currency)),
                Tables\Columns\TextColumn::make('provider_charge_id')->label(__('admin.payments.fields.charge_id'))->searchable()->copyable()->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('provider_event_id')->label(__('admin.payments.fields.event_id'))->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('payment_order_id')->label(__('admin.payments.fields.order'))->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.payments.fields.status'))
                    ->options(AdminLabels::options('payments.status', [Payment::STATUS_SUCCEEDED, Payment::STATUS_REFUNDED, Payment::STATUS_FAILED])),
                Tables\Filters\SelectFilter::make('kind')
                    ->label(__('admin.payments.fields.kind'))
                    ->options(AdminLabels::options('payments.kind', [Payment::KIND_CHARGE, Payment::KIND_RENEWAL, Payment::KIND_REFUND])),
                Tables\Filters\SelectFilter::make('provider')
                    ->label(__('admin.payments.fields.provider'))
                    ->options(AdminLabels::options('common.provider', array_map(static fn (SubscriptionProvider $p): string => $p->value, SubscriptionProvider::paid()))),
                Tables\Filters\SelectFilter::make('currency')
                    ->label(__('admin.payments.fields.currency'))
                    ->options(['UZS' => 'UZS', 'XTR' => 'XTR']),
                Tables\Filters\SelectFilter::make('subscriber_type')
                    ->label(__('admin.payments.fields.subscriber'))
                    ->options(AdminLabels::options('common.subscriber_type', ['learner', 'organization'])),
            ])
            ->emptyStateHeading(__('admin.payments.empty'))
            ->actions([
                Actions\ViewAction::make(),
                PaymentActions::refund(),
            ])
            ->defaultSort('id', 'desc');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
            'view' => Pages\ViewPayment::route('/{record}'),
        ];
    }

    private static function statusColor(string $state): string
    {
        return match ($state) {
            Payment::STATUS_SUCCEEDED => 'success',
            Payment::STATUS_FAILED => 'danger',
            default => 'warning',
        };
    }
}
