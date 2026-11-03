<?php

declare(strict_types=1);

namespace App\Filament\Resources\GameRunResource\RelationManagers;

use App\Domain\Game\Enums\AttemptKind;
use App\Domain\Practice\Enums\Verdict;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\Support\AdminLabels;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The answers of a run: `game_run_attempts`, the journal of accepted requests (read-only). The stored `payload` and `result` are left
 * out - the result carries the expected answer; the verdict, the first-try mark and the time are what support looks at.
 */
class AttemptsRelationManager extends RelationManager
{
    use RequiresActiveAdmin;

    protected static string $relationship = 'attempts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.game.runs.attempts.title');
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
            ->modelLabel(__('admin.game.runs.attempts.singular'))
            ->pluralModelLabel(__('admin.game.runs.attempts.plural'))
            ->emptyStateHeading(__('admin.game.runs.attempts.empty'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->select(['id', 'run_id', 'element_id', 'kind', 'verdict', 'is_first', 'time_spent_ms', 'accepted_at']))
            ->columns([
                Tables\Columns\TextColumn::make('element_id')->label(__('admin.game.runs.attempts.columns.element'))->fontFamily('mono'),
                Tables\Columns\TextColumn::make('kind')
                    ->label(__('admin.game.runs.attempts.columns.kind'))
                    ->badge()
                    ->formatStateUsing(fn (AttemptKind $state): string => AdminLabels::value('game.runs.attempt_kind', $state->value)),
                Tables\Columns\TextColumn::make('verdict')
                    ->label(__('admin.game.runs.attempts.columns.verdict'))
                    ->formatStateUsing(fn (?Verdict $state): string => AdminLabels::value('game.runs.verdict', $state?->value))
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('is_first')->label(__('admin.game.runs.attempts.columns.is_first'))->boolean(),
                Tables\Columns\TextColumn::make('time_spent_ms')->label(__('admin.game.runs.attempts.columns.time'))->suffix(' мс')->placeholder('—'),
                Tables\Columns\TextColumn::make('accepted_at')->label(__('admin.game.runs.attempts.columns.accepted_at'))->dateTime(),
            ])
            ->paginated(false)
            ->defaultSort('id');
    }
}
