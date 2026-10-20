<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Billing\Support\PriceFormatter;
use App\Filament\Resources\ProviderTransactionResource\Pages;
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

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 60;

    protected static ?string $modelLabel = 'provider transaction';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Transaction')->columns(3)->schema([
                TextEntry::make('id')->label('#'),
                TextEntry::make('provider')->badge(),
                TextEntry::make('state_label')->label('State')->badge()->state(fn (ProviderTransaction $record): string => self::stateLabel($record->state))->color(fn (ProviderTransaction $record): string => self::stateColor($record->state)),
                TextEntry::make('provider_transaction_id')->label('Provider transaction id')->copyable(),
                TextEntry::make('payment_order_id')->label('Order #')
                    ->url(fn (ProviderTransaction $record): string => PaymentOrderResource::getUrl('view', ['record' => $record->payment_order_id])),
                TextEntry::make('amount_label')->label('Amount')->state(fn (ProviderTransaction $record): string => self::amountLabel($record)),
                TextEntry::make('created_at_provider')->dateTime()->placeholder('—'),
                TextEntry::make('performed_at')->dateTime()->placeholder('—'),
                TextEntry::make('canceled_at')->dateTime()->placeholder('—'),
                TextEntry::make('cancel_reason')->placeholder('—'),
            ]),
            Section::make('Webhook journal')->collapsed()->schema([
                TextEntry::make('history')->hiddenLabel()->state(fn (ProviderTransaction $record) => BillingDisplay::payload($record->history))->html(),
            ]),
            Section::make('Last provider request (secrets masked)')->collapsed()->schema([
                TextEntry::make('raw')->hiddenLabel()->state(fn (ProviderTransaction $record) => BillingDisplay::payload($record->raw))->html(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('paymentOrder'))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('provider')->badge()->sortable(),
                Tables\Columns\TextColumn::make('provider_transaction_id')->label('Provider transaction id')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('payment_order_id')->label('Order #')->sortable(),
                Tables\Columns\TextColumn::make('state')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => self::stateLabel($state))
                    ->color(fn (int $state): string => self::stateColor($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount_label')
                    ->label('Amount')
                    ->state(fn (ProviderTransaction $record): string => self::amountLabel($record)),
                Tables\Columns\TextColumn::make('performed_at')->dateTime()->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('canceled_at')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('provider')->options(BillingDisplay::options('payme', 'click')),
                Tables\Filters\SelectFilter::make('state')->options([
                    (string) ProviderTransaction::STATE_CREATED => 'Created / prepared',
                    (string) ProviderTransaction::STATE_PERFORMED => 'Performed / completed',
                    (string) ProviderTransaction::STATE_CANCELED => 'Canceled',
                    (string) ProviderTransaction::STATE_CANCELED_AFTER_PERFORM => 'Canceled after perform',
                ]),
            ])
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
            ProviderTransaction::STATE_CREATED => 'Created',
            ProviderTransaction::STATE_PERFORMED => 'Performed',
            ProviderTransaction::STATE_CANCELED => 'Canceled',
            ProviderTransaction::STATE_CANCELED_AFTER_PERFORM => 'Canceled after perform',
            default => 'State '.$state,
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
