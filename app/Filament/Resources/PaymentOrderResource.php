<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Billing\Support\OrgTiers;
use App\Domain\Billing\Support\PayloadMasker;
use App\Domain\Billing\Support\PriceFormatter;
use App\Filament\Resources\PaymentOrderResource\Pages;
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

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 50;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Order')->columns(3)->schema([
                TextEntry::make('id')->label('#'),
                TextEntry::make('status')->badge()->color(fn (string $state): string => self::statusColor($state)),
                TextEntry::make('token_label')->label('Token')->state(fn (PaymentOrder $record): string => PayloadMasker::token($record->token)),
                TextEntry::make('subscriber_label')->label('Subject')->state(fn (PaymentOrder $record): string => BillingDisplay::subscriber($record->subscriber_type, $record->subscriber_id, $record->subscriber)),
                TextEntry::make('plan')->badge(),
                TextEntry::make('tier')->badge()->placeholder('—'),
                TextEntry::make('period')->badge(),
                TextEntry::make('provider')->badge(),
                TextEntry::make('created_via')->badge(),
                TextEntry::make('amount_label')->label('Amount')->state(fn (PaymentOrder $record): string => PriceFormatter::plain($record->amount, $record->currency)),
                TextEntry::make('list_amount_label')->label('List price')->state(fn (PaymentOrder $record): string => PriceFormatter::plain($record->list_amount, $record->currency)),
                TextEntry::make('offer_id')->label('Offer #')->placeholder('—'),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('expires_at')->dateTime(),
                TextEntry::make('paid_at')->dateTime()->placeholder('—'),
                TextEntry::make('subscription_id')->label('Subscription #')->placeholder('—'),
            ]),
            Section::make('Meta (secrets masked)')->collapsed()->schema([
                TextEntry::make('meta')->hiddenLabel()->state(fn (PaymentOrder $record) => BillingDisplay::payload($record->meta))->html(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('subscriber'))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('subscriber_label')
                    ->label('Subject')
                    ->state(fn (PaymentOrder $record): string => BillingDisplay::subscriber($record->subscriber_type, $record->subscriber_id, $record->subscriber)),
                Tables\Columns\TextColumn::make('tier')->badge()->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('period')->badge()->toggleable(),
                Tables\Columns\TextColumn::make('provider')->badge()->sortable(),
                Tables\Columns\TextColumn::make('amount_label')
                    ->label('Amount')
                    ->state(fn (PaymentOrder $record): string => PriceFormatter::plain($record->amount, $record->currency)),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state): string => self::statusColor($state))->sortable(),
                Tables\Columns\TextColumn::make('created_via')->badge()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('expires_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('paid_at')->dateTime()->placeholder('—')->sortable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(BillingDisplay::options(PaymentOrder::STATUS_PENDING, PaymentOrder::STATUS_PAID, PaymentOrder::STATUS_CANCELED, PaymentOrder::STATUS_EXPIRED, PaymentOrder::STATUS_FAILED)),
                Tables\Filters\SelectFilter::make('provider')->options(BillingDisplay::options('telegram_stars', 'payme', 'click')),
                Tables\Filters\SelectFilter::make('currency')->options(['UZS' => 'UZS', 'XTR' => 'XTR']),
                Tables\Filters\SelectFilter::make('subscriber_type')->label('Subject')->options(['learner' => 'Learner', 'organization' => 'Organization']),
                Tables\Filters\SelectFilter::make('tier')->options(BillingDisplay::options(...OrgTiers::keys())),
            ])
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
