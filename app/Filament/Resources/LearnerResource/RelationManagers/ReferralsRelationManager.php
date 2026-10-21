<?php

declare(strict_types=1);

namespace App\Filament\Resources\LearnerResource\RelationManagers;

use App\Domain\Engagement\Enums\ReferralStatus;
use App\Domain\Engagement\Services\ReferralService;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\LearnerResource;
use App\Models\Referral;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The learners this one invited (docs/13 §1.5 `referrals`, §7.1), read-only except the two actions on a
 * flagged row: "Clear flag" (the parked reward is granted at once when the referral is activated) and
 * "Reject" (`rejected {flagged}`). Both are audited (`referral.unflagged` / `referral.rejected`). Admin only.
 */
class ReferralsRelationManager extends RelationManager
{
    use RequiresActiveAdmin;

    protected static string $relationship = 'referrals';

    protected static ?string $title = 'Referrals';

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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('referee'))
            ->columns([
                Tables\Columns\TextColumn::make('referee_name')
                    ->label('Invited learner')
                    ->state(function (Referral $record): string {
                        $referee = $record->referee;

                        return $referee === null ? '' : trim(($referee->first_name ?? '').' '.($referee->last_name ?? ''));
                    })
                    ->placeholder('—')
                    ->url(fn (Referral $record): ?string => $record->referee !== null
                        ? LearnerResource::getUrl('view', ['record' => $record->referee_learner_id])
                        : null),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ReferralStatus::Rewarded->value => 'success',
                        ReferralStatus::Activated->value => 'info',
                        ReferralStatus::Rejected->value => 'gray',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('reject_reason')->label('Reason')->placeholder('—'),
                Tables\Columns\IconColumn::make('flagged')
                    ->label('Flagged')
                    ->state(fn (Referral $record): bool => $record->flagged_at !== null)
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('gray'),
                Tables\Columns\TextColumn::make('linked_at')->dateTime(),
                Tables\Columns\TextColumn::make('activated_at')->dateTime()->placeholder('—'),
                Tables\Columns\TextColumn::make('rewarded_at')->dateTime()->placeholder('—'),
            ])
            ->actions([
                Action::make('unflag')
                    ->label('Clear flag')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Referral $record): bool => $record->flagged_at !== null && $record->status !== ReferralStatus::Rejected->value)
                    ->requiresConfirmation()
                    ->modalHeading('Clear the burst flag')
                    ->modalDescription('The referral is treated as genuine: an activated one is rewarded at once (7 days of Pro to both). The action is written to the audit log.')
                    ->action(function (Referral $record): void {
                        $user = auth()->user();
                        if (! $user instanceof User) {
                            return;
                        }

                        app(ReferralService::class)->unflag($record, $user, request()->ip());

                        Notification::make()->title('Flag cleared')->success()->send();
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Referral $record): bool => $record->flagged_at !== null
                        && ! in_array($record->status, [ReferralStatus::Rewarded->value, ReferralStatus::Rejected->value], true))
                    ->requiresConfirmation()
                    ->modalHeading('Reject this referral')
                    ->modalDescription('No reward will ever be granted for it. The action is written to the audit log.')
                    ->action(function (Referral $record): void {
                        $user = auth()->user();
                        if (! $user instanceof User) {
                            return;
                        }

                        app(ReferralService::class)->rejectFlagged($record, $user, request()->ip());

                        Notification::make()->title('Referral rejected')->success()->send();
                    }),
            ])
            ->paginated([25, 50])
            ->defaultSort('id', 'desc');
    }
}
