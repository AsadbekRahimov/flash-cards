<?php

declare(strict_types=1);

namespace App\Filament\Resources\LearnerResource\RelationManagers;

use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\SubscriptionResource;
use App\Filament\Resources\Support\AdminLabels;
use App\Models\Learner;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The learner's own subscriptions, read-only (granting / revoking stays on the Subscriptions screen,
 * which goes through SubscriptionService and the audit log). Sponsored Pro is a subscription of the
 * organization and is not listed here. Admin only.
 */
class SubscriptionsRelationManager extends RelationManager
{
    use RequiresActiveAdmin;

    protected static string $relationship = 'subscriptions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.learners.subscriptions.title');
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
            ->modelLabel(__('admin.learners.subscriptions.singular'))
            ->pluralModelLabel(__('admin.learners.subscriptions.plural'))
            ->emptyStateHeading(__('admin.learners.subscriptions.empty'))
            ->columns([
                Tables\Columns\TextColumn::make('plan')
                    ->label(__('admin.learners.subscriptions.columns.plan'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('common.plan', $state)),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.learners.subscriptions.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('common.subscription_status', $state))
                    ->color(fn (string $state): string => match ($state) {
                        'active', 'trialing' => 'success',
                        'past_due' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('provider')
                    ->label(__('admin.learners.subscriptions.columns.provider'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('common.provider', $state)),
                Tables\Columns\TextColumn::make('starts_at')->label(__('admin.learners.subscriptions.columns.starts_at'))->dateTime(),
                Tables\Columns\TextColumn::make('current_period_ends_at')->label(__('admin.learners.subscriptions.columns.ends_at'))->dateTime()->placeholder(__('admin.learners.subscriptions.no_end')),
                Tables\Columns\TextColumn::make('note')->label(__('admin.learners.subscriptions.columns.note'))->limit(40)->placeholder('—'),
            ])
            ->headerActions([
                Action::make('openSubscriptions')
                    ->label(__('admin.learners.subscriptions.open_in_resource'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(function (): string {
                        /** @var Learner $learner */
                        $learner = $this->getOwnerRecord();

                        return SubscriptionResource::getUrl('index', ['filters' => ['learner' => ['learner_id' => $learner->id]]]);
                    }),
            ])
            ->paginated(false)
            ->defaultSort('id', 'desc');
    }
}
