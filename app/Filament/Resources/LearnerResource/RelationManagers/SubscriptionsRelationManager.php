<?php

declare(strict_types=1);

namespace App\Filament\Resources\LearnerResource\RelationManagers;

use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\SubscriptionResource;
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

    protected static ?string $title = 'Subscriptions';

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
                Tables\Columns\TextColumn::make('plan')->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active', 'trialing' => 'success',
                        'past_due' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('provider')->badge(),
                Tables\Columns\TextColumn::make('starts_at')->dateTime(),
                Tables\Columns\TextColumn::make('current_period_ends_at')->label('Until')->dateTime()->placeholder('No end'),
                Tables\Columns\TextColumn::make('note')->limit(40)->placeholder('—'),
            ])
            ->headerActions([
                Action::make('openSubscriptions')
                    ->label('Open in Subscriptions')
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
