<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Filament\Resources\SubscriptionResource\Pages;
use App\Filament\Resources\SubscriptionResource\Support\SubscriptionActions;
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

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 10;

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
                    ->label('Subject')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'organization' ? 'info' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('subscriber_label')
                    ->label('Name')
                    ->state(fn (Subscription $record): string => self::subscriberLabel($record)),
                Tables\Columns\TextColumn::make('plan')->badge()->sortable(),
                Tables\Columns\TextColumn::make('tier')->badge()->placeholder('—')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('seats_limit')->label('Seats')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active', 'trialing' => 'success',
                        'past_due' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('provider')->badge()->sortable(),
                Tables\Columns\TextColumn::make('starts_at')->dateTime()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('current_period_ends_at')
                    ->label('Until')
                    ->dateTime()
                    ->placeholder('No end')
                    ->sortable(),
                Tables\Columns\TextColumn::make('grantedBy.name')->label('Granted by')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('note')->limit(40)->placeholder('—')->tooltip(fn (Subscription $record): ?string => $record->note)->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(self::options(array_column(SubscriptionStatus::cases(), 'value'))),
                Tables\Filters\SelectFilter::make('provider')->options(self::options(array_column(SubscriptionProvider::cases(), 'value'))),
                Tables\Filters\SelectFilter::make('plan')->options(self::options(array_keys((array) config('billing.plans')))),
                Tables\Filters\SelectFilter::make('subscriber_type')->label('Subject')->options(['learner' => 'Learner', 'organization' => 'Organization']),
                // Target of the "Subscriptions" link on a learner's page: ?filters[learner][learner_id]=<id>.
                Tables\Filters\Filter::make('learner')
                    ->schema([Forms\Components\TextInput::make('learner_id')->label('Learner ID')->numeric()->minValue(1)])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['learner_id'] ?? null)
                        ? $query->where('subscriber_type', 'learner')->where('subscriber_id', (int) $data['learner_id'])
                        : $query)
                    ->indicateUsing(fn (array $data): ?string => filled($data['learner_id'] ?? null) ? 'Learner #'.$data['learner_id'] : null),
                Tables\Filters\TernaryFilter::make('live')
                    ->label('Live status')
                    ->placeholder('All')
                    ->trueLabel('Live (trialing / active / past due)')
                    ->falseLabel('Ended (canceled / expired)')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereIn('status', SubscriptionStatus::liveValues()),
                        false: fn (Builder $query): Builder => $query->whereNotIn('status', SubscriptionStatus::liveValues()),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
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

        return '#'.$record->subscriber_id.' (deleted)';
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private static function options(array $values): array
    {
        return array_combine($values, array_map(ucfirst(...), str_replace('_', ' ', $values)));
    }
}
