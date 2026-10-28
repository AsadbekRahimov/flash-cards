<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Filament\Resources\SubscriptionResource\Pages;
use App\Filament\Resources\SubscriptionResource\Support\SubscriptionActions;
use App\Filament\Resources\Support\AdminLabels;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\Subscription;
use Filament\Forms;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Subscriptions (docs/12 §5.5). Admin only (SubscriptionPolicy). Rows are never edited by hand:
 * "Grant Pro" and "Revoke" go through SubscriptionService, which owns the rules and the audit log.
 */
class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.billing');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.subscriptions.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.subscriptions.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.subscriptions.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['subscriber', 'grantedBy']))
            ->columns([
                Tables\Columns\TextColumn::make('subscriber_type')
                    ->label(__('admin.subscriptions.fields.subscriber_type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('common.subscriber_type', $state))
                    ->color(fn (string $state): string => $state === 'organization' ? 'info' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('subscriber_label')
                    ->label(__('admin.subscriptions.fields.name'))
                    ->state(fn (Subscription $record): string => self::subscriberLabel($record)),
                Tables\Columns\TextColumn::make('plan')
                    ->label(__('admin.subscriptions.fields.plan'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('common.plan', $state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('tier')
                    ->label(__('admin.subscriptions.fields.tier'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => AdminLabels::value('common.tier', $state))
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('seats_limit')->label(__('admin.subscriptions.fields.seats_limit'))->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.subscriptions.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('common.subscription_status', $state))
                    ->color(fn (string $state): string => match ($state) {
                        'active', 'trialing' => 'success',
                        'past_due' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('provider')
                    ->label(__('admin.subscriptions.fields.provider'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('common.provider', $state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('starts_at')->label(__('admin.subscriptions.fields.starts_at'))->dateTime()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('current_period_ends_at')
                    ->label(__('admin.subscriptions.fields.ends_at'))
                    ->dateTime()
                    ->placeholder(__('admin.subscriptions.fields.no_end'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('grantedBy.name')->label(__('admin.subscriptions.fields.granted_by'))->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('note')->label(__('admin.subscriptions.fields.note'))->limit(40)->placeholder('—')->tooltip(fn (Subscription $record): ?string => $record->note)->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.subscriptions.fields.status'))
                    ->options(AdminLabels::options('common.subscription_status', array_column(SubscriptionStatus::cases(), 'value'))),
                Tables\Filters\SelectFilter::make('provider')
                    ->label(__('admin.subscriptions.fields.provider'))
                    ->options(AdminLabels::options('common.provider', array_column(SubscriptionProvider::cases(), 'value'))),
                Tables\Filters\SelectFilter::make('plan')
                    ->label(__('admin.subscriptions.fields.plan'))
                    ->options(AdminLabels::options('common.plan', array_keys((array) config('billing.plans')))),
                Tables\Filters\SelectFilter::make('subscriber_type')
                    ->label(__('admin.subscriptions.fields.subscriber_type'))
                    ->options(AdminLabels::options('common.subscriber_type', ['learner', 'organization'])),
                // Target of the "Subscriptions" link on a learner's page: ?filters[learner][learner_id]=<id>.
                Tables\Filters\Filter::make('learner')
                    ->schema([Forms\Components\TextInput::make('learner_id')->label(__('admin.subscriptions.filters.learner_id'))->numeric()->minValue(1)])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['learner_id'] ?? null)
                        ? $query->where('subscriber_type', 'learner')->where('subscriber_id', (int) $data['learner_id'])
                        : $query)
                    ->indicateUsing(function (array $data): ?string {
                        if (blank($data['learner_id'] ?? null)) {
                            return null;
                        }

                        return __('admin.subscriptions.filters.learner_indicator', ['id' => $data['learner_id']]);
                    }),
                Tables\Filters\TernaryFilter::make('live')
                    ->label(__('admin.subscriptions.filters.live'))
                    ->placeholder(__('admin.common.all'))
                    ->trueLabel(__('admin.subscriptions.filters.live_true'))
                    ->falseLabel(__('admin.subscriptions.filters.live_false'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereIn('status', SubscriptionStatus::liveValues()),
                        false: fn (Builder $query): Builder => $query->whereNotIn('status', SubscriptionStatus::liveValues()),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->emptyStateHeading(__('admin.subscriptions.empty'))
            ->actions([SubscriptionActions::revoke()])
            ->defaultSort('id', 'desc');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptions::route('/'),
        ];
    }

    private static function subscriberLabel(Subscription $record): string
    {
        $subscriber = $record->subscriber;

        if ($subscriber instanceof Learner) {
            $name = trim(($subscriber->first_name ?? '').' '.($subscriber->last_name ?? ''));
            $username = $subscriber->username !== null ? ' @'.$subscriber->username : '';

            return trim($name.$username).' #'.$subscriber->id;
        }

        if ($subscriber instanceof Organization) {
            return $subscriber->name.' #'.$subscriber->id;
        }

        return '#'.$record->subscriber_id.' '.__('admin.common.deleted');
    }
}
