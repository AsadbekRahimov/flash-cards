<?php

declare(strict_types=1);

namespace App\Filament\Resources\CupResource\RelationManagers;

use App\Domain\Cup\Enums\CupParticipantStatus;
use App\Domain\Cup\Services\CupPlatformAdmin;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\CupResource;
use App\Filament\Resources\Support\AdminLabels;
use App\Filament\Resources\Support\AdminTable;
use App\Models\CupParticipant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The participants of a cup (docs/33 §8, K9) in the order of its table: total, then the round points of the counted days. One cup is
 * tens of rows read through the unique `(cup_id, learner_id)` and sorted in memory by PostgreSQL - no index on the scores (their updates
 * stay HOT, docs/33 §11.2). "Сбросить имя" puts the Telegram first name back ({@see CupPlatformAdmin::resetName()}, `audit_logs`).
 */
class ParticipantsRelationManager extends RelationManager
{
    use RequiresActiveAdmin;

    protected static string $relationship = 'participants';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.cups.participants.title');
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
        return AdminTable::bounded($table)
            ->modelLabel(__('admin.cups.participants.singular'))
            ->pluralModelLabel(__('admin.cups.participants.plural'))
            ->heading(__('admin.cups.participants.title'))
            ->emptyStateHeading(__('admin.cups.participants.empty'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->select(['id', 'cup_id', 'learner_id', 'display_name', 'status', 'joined_at', 'first_day', 'left_at', 'best_total', 'best_round_points', 'days_played', 'final_rank', 'rename_count']))
            ->columns([
                Tables\Columns\TextColumn::make('final_rank')->label(__('admin.cups.participants.columns.final_rank'))->placeholder('—'),
                Tables\Columns\TextColumn::make('display_name')->label(__('admin.cups.participants.columns.display_name')),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.cups.participants.columns.status'))
                    ->badge()
                    ->state(fn (CupParticipant $record): string => $record->status->value)
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('cups.participant_status', $state))
                    ->color(fn (string $state): string => $state === CupParticipantStatus::Active->value ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('best_total')->label(__('admin.cups.participants.columns.best_total')),
                Tables\Columns\TextColumn::make('best_round_points')->label(__('admin.cups.participants.columns.best_round_points')),
                Tables\Columns\TextColumn::make('days_played')->label(__('admin.cups.participants.columns.days_played')),
                Tables\Columns\TextColumn::make('first_day')->label(__('admin.cups.participants.columns.first_day')),
                Tables\Columns\TextColumn::make('joined_at')->label(__('admin.cups.participants.columns.joined_at'))->dateTime(),
                Tables\Columns\TextColumn::make('left_at')->label(__('admin.cups.participants.columns.left_at'))->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('reset_name')
                    ->label(__('admin.cups.participants.actions.reset_name'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.cups.participants.actions.reset_name_confirm'))
                    ->visible(fn (): bool => self::isActiveAdmin())
                    ->authorize(fn (): bool => CupResource::can('manage'))
                    ->action(fn (CupParticipant $record) => CupResource::run(
                        fn (CupPlatformAdmin $admin, User $user): bool => $admin->resetName($record, $user, request()->ip()),
                        'admin.cups.participants.notifications.reset',
                        'admin.cups.participants.notifications.unchanged',
                    )),
            ])
            // The order of the table of the cup: the total, then its round part (the first tie-break).
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('best_total')->orderByDesc('best_round_points'));
    }
}
