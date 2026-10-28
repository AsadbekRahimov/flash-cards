<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Billing\Support\PriceFormatter;
use App\Filament\Resources\ProviderTransactionResource\Pages;
use App\Filament\Resources\Support\AdminLabels;
use App\Filament\Resources\Support\BillingDisplay;
use App\Models\ProviderTransaction;
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
 * Provider transactions (Payme / Click state machine, docs/13 §1.2). Read-only, admin only
 * (ProviderTransactionPolicy). The stored `raw` is already redacted by the adapters and is masked
 * once more for display; the `history` is the webhook journal.
 */
class ProviderTransactionResource extends Resource
{
    protected static ?string $model = ProviderTransaction::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?int $navigationSort = 60;

    /** The labels are several words long: Filament's title-casing ("Транзакция Провайдера") is wrong in Russian. */
    protected static bool $hasTitleCaseModelLabel = false;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.billing');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.provider_transactions.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.provider_transactions.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.provider_transactions.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.provider_transactions.sections.transaction'))->columns(3)->schema([
                TextEntry::make('id')->label('#'),
                TextEntry::make('provider')
                    ->label(__('admin.provider_transactions.fields.provider'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('common.provider', $state)),
                TextEntry::make('state_label')->label(__('admin.provider_transactions.fields.state'))->badge()->state(fn (ProviderTransaction $record): string => self::stateLabel($record->state))->color(fn (ProviderTransaction $record): string => self::stateColor($record->state)),
                TextEntry::make('provider_transaction_id')->label(__('admin.provider_transactions.fields.provider_transaction_id'))->copyable(),
                TextEntry::make('payment_order_id')->label(__('admin.provider_transactions.fields.order'))
                    ->url(fn (ProviderTransaction $record): string => PaymentOrderResource::getUrl('view', ['record' => $record->payment_order_id])),
                TextEntry::make('amount_label')->label(__('admin.provider_transactions.fields.amount'))->state(fn (ProviderTransaction $record): string => self::amountLabel($record)),
                TextEntry::make('created_at_provider')->label(__('admin.provider_transactions.fields.created_at_provider'))->dateTime()->placeholder('—'),
                TextEntry::make('performed_at')->label(__('admin.provider_transactions.fields.performed_at'))->dateTime()->placeholder('—'),
                TextEntry::make('canceled_at')->label(__('admin.provider_transactions.fields.canceled_at'))->dateTime()->placeholder('—'),
                TextEntry::make('cancel_reason')->label(__('admin.provider_transactions.fields.cancel_reason'))->placeholder('—'),
            ]),
            Section::make(__('admin.provider_transactions.sections.journal'))->collapsed()->schema([
                TextEntry::make('history')->label(__('admin.provider_transactions.sections.journal'))->hiddenLabel()->state(fn (ProviderTransaction $record) => BillingDisplay::payload($record->history))->html(),
            ]),
            Section::make(__('admin.provider_transactions.sections.raw'))->collapsed()->schema([
                TextEntry::make('raw')->label(__('admin.provider_transactions.sections.raw'))->hiddenLabel()->state(fn (ProviderTransaction $record) => BillingDisplay::payload($record->raw))->html(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('paymentOrder'))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label(__('admin.provider_transactions.fields.created_at'))->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('provider')
                    ->label(__('admin.provider_transactions.fields.provider'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('common.provider', $state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('provider_transaction_id')->label(__('admin.provider_transactions.fields.provider_transaction_id'))->searchable()->copyable(),
                Tables\Columns\TextColumn::make('payment_order_id')->label(__('admin.provider_transactions.fields.order'))->sortable(),
                Tables\Columns\TextColumn::make('state')
                    ->label(__('admin.provider_transactions.fields.state'))
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => self::stateLabel($state))
                    ->color(fn (int $state): string => self::stateColor($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount_label')
                    ->label(__('admin.provider_transactions.fields.amount'))
                    ->state(fn (ProviderTransaction $record): string => self::amountLabel($record)),
                Tables\Columns\TextColumn::make('performed_at')->label(__('admin.provider_transactions.fields.performed_at'))->dateTime()->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('canceled_at')->label(__('admin.provider_transactions.fields.canceled_at'))->dateTime()->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('provider')
                    ->label(__('admin.provider_transactions.fields.provider'))
                    ->options(AdminLabels::options('common.provider', ['payme', 'click'])),
                Tables\Filters\SelectFilter::make('state')
                    ->label(__('admin.provider_transactions.fields.state'))
                    ->options([
                        (string) ProviderTransaction::STATE_CREATED => __('admin.provider_transactions.state_filter.created'),
                        (string) ProviderTransaction::STATE_PERFORMED => __('admin.provider_transactions.state_filter.performed'),
                        (string) ProviderTransaction::STATE_CANCELED => __('admin.provider_transactions.state_filter.canceled'),
                        (string) ProviderTransaction::STATE_CANCELED_AFTER_PERFORM => __('admin.provider_transactions.state_filter.canceled_after_perform'),
                    ]),
            ])
            ->emptyStateHeading(__('admin.provider_transactions.empty'))
            ->actions([Actions\ViewAction::make()])
            ->defaultSort('id', 'desc');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProviderTransactions::route('/'),
            'view' => Pages\ViewProviderTransaction::route('/{record}'),
        ];
    }

    private static function stateLabel(int $state): string
    {
        return match ($state) {
            ProviderTransaction::STATE_CREATED => __('admin.provider_transactions.state.created'),
            ProviderTransaction::STATE_PERFORMED => __('admin.provider_transactions.state.performed'),
            ProviderTransaction::STATE_CANCELED => __('admin.provider_transactions.state.canceled'),
            ProviderTransaction::STATE_CANCELED_AFTER_PERFORM => __('admin.provider_transactions.state.canceled_after_perform'),
            default => __('admin.provider_transactions.state.unknown', ['state' => $state]),
        };
    }

    private static function stateColor(int $state): string
    {
        return match ($state) {
            ProviderTransaction::STATE_PERFORMED => 'success',
            ProviderTransaction::STATE_CREATED => 'warning',
            ProviderTransaction::STATE_CANCELED_AFTER_PERFORM => 'danger',
            default => 'gray',
        };
    }

    private static function amountLabel(ProviderTransaction $record): string
    {
        return PriceFormatter::plain($record->amount, $record->paymentOrder->currency ?? 'UZS');
    }
}
