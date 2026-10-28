<?php

declare(strict_types=1);

namespace App\Filament\Resources\LearnerResource\RelationManagers;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\OfferService;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\Support\AdminErrors;
use App\Filament\Resources\Support\AdminLabels;
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

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.learners.offers.title');
    }

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
            ->modelLabel(__('admin.learners.offers.singular'))
            ->pluralModelLabel(__('admin.learners.offers.plural'))
            ->emptyStateHeading(__('admin.learners.offers.empty'))
            ->columns([
                Tables\Columns\TextColumn::make('kind')
                    ->label(__('admin.learners.offers.columns.kind'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('learners.offers.kind', $state)),
                Tables\Columns\TextColumn::make('discount_pct')->label(__('admin.learners.offers.columns.discount_pct'))->suffix(' %'),
                Tables\Columns\TextColumn::make('plan')
                    ->label(__('admin.learners.offers.columns.plan'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => AdminLabels::value('common.plan', $state))
                    ->placeholder(__('admin.learners.offers.any')),
                Tables\Columns\TextColumn::make('period')
                    ->label(__('admin.learners.offers.columns.period'))
                    ->formatStateUsing(fn (?string $state): string => AdminLabels::value('common.period', $state))
                    ->placeholder(__('admin.learners.offers.any')),
                Tables\Columns\TextColumn::make('state')
                    ->label(__('admin.learners.offers.columns.state'))
                    ->badge()
                    ->state(fn (Offer $record): string => self::state($record))
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('learners.offers.state', $state))
                    ->color(fn (string $state): string => $state === 'open' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('expires_at')->label(__('admin.learners.offers.columns.expires_at'))->dateTime(),
                Tables\Columns\TextColumn::make('redeemed_at')->label(__('admin.learners.offers.columns.redeemed_at'))->dateTime()->placeholder('—'),
                Tables\Columns\TextColumn::make('sourceOrganization.name')->label(__('admin.learners.offers.columns.center'))->placeholder('—'),
                Tables\Columns\TextColumn::make('payment_order_id')->label(__('admin.learners.offers.columns.order'))->placeholder('—'),
            ])
            ->actions([
                Action::make('annul')
                    ->label(__('admin.learners.offers.actions.annul'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Offer $record): bool => $record->isOpenAt(CarbonImmutable::now()))
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.learners.offers.actions.annul_heading'))
                    ->modalDescription(__('admin.learners.offers.actions.annul_help'))
                    ->action(function (Offer $record): void {
                        $user = auth()->user();
                        if (! $user instanceof User) {
                            return;
                        }

                        try {
                            app(OfferService::class)->annul($record, $user, request()->ip());
                        } catch (BillingException $e) {
                            Notification::make()->title(__('admin.learners.offers.notifications.failed'))->body(AdminErrors::for('learners.offers', $e))->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('admin.learners.offers.notifications.annulled'))->success()->send();
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
