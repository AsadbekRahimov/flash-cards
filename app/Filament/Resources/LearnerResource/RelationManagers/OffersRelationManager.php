<?php

declare(strict_types=1);

namespace App\Filament\Resources\LearnerResource\RelationManagers;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\OfferService;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Models\Offer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The learner's personal discounts (docs/13 §1.2 `offers`), read-only except "Annul": an unused offer
 * expires now through {@see OfferService::annul()} (audited as `offer.annulled`). Admin only.
 */
class OffersRelationManager extends RelationManager
{
    use RequiresActiveAdmin;

    protected static string $relationship = 'offers';

    protected static ?string $title = 'Offers';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::isActiveAdmin();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('kind')->badge(),
                Tables\Columns\TextColumn::make('discount_pct')->label('Discount')->suffix(' %'),
                Tables\Columns\TextColumn::make('plan')->badge()->placeholder('any'),
                Tables\Columns\TextColumn::make('period')->placeholder('any'),
                Tables\Columns\TextColumn::make('state')
                    ->badge()
                    ->state(fn (Offer $record): string => self::state($record))
                    ->color(fn (string $state): string => $state === 'open' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('expires_at')->dateTime(),
                Tables\Columns\TextColumn::make('redeemed_at')->dateTime()->placeholder('—'),
                Tables\Columns\TextColumn::make('sourceOrganization.name')->label('Center')->placeholder('—'),
                Tables\Columns\TextColumn::make('payment_order_id')->label('Order')->placeholder('—'),
            ])
            ->actions([
                Action::make('annul')
                    ->label('Annul')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Offer $record): bool => $record->isOpenAt(CarbonImmutable::now()))
                    ->requiresConfirmation()
                    ->modalHeading('Annul this offer')
                    ->modalDescription('The learner loses the discount at once. The action is written to the audit log.')
                    ->action(function (Offer $record): void {
                        $user = auth()->user();
                        if (! $user instanceof User) {
                            return;
                        }

                        try {
                            app(OfferService::class)->annul($record, $user, request()->ip());
                        } catch (BillingException $e) {
                            Notification::make()->title('Not annulled')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Offer annulled')->success()->send();
                    }),
            ])
            ->paginated(false)
            ->defaultSort('id', 'desc');
    }

    private static function state(Offer $offer): string
    {
        return match (true) {
            $offer->redeemed_at !== null => 'redeemed',
            $offer->expires_at->isPast() => 'expired',
            default => 'open',
        };
    }
}
