<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources\AssignmentResource\RelationManagers;

use App\Filament\Org\Resources\MemberResource;
use App\Models\Assignment;
use App\Models\AssignmentResult;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-learner progress of an assignment (docs/13 §1.4): the snapshot rows of the learners who are
 * still active in the group. Read-only and aggregate-only — no sessions, answers or Telegram ids
 * (docs/12 §4.4). Visibility follows the assignment (`OrgAssignmentResultPolicy`).
 */
class ResultsRelationManager extends RelationManager
{
    protected static string $relationship = 'results';

    protected static bool $isLazy = false;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('org.assignments.results.heading');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description(__('org.assignments.results.privacy'))
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['learner', 'assignment'])
                ->whereExists(fn ($sub) => $sub
                    ->from('group_members')
                    ->whereColumn('group_members.learner_id', 'assignment_results.learner_id')
                    ->whereColumn('group_members.telegram_group_id', 'assignments.telegram_group_id')
                    ->where('group_members.status', 'active'))
                ->join('assignments', 'assignments.id', '=', 'assignment_results.assignment_id')
                ->select('assignment_results.*'))
            ->columns([
                Tables\Columns\TextColumn::make('learner')
                    ->label(__('org.assignments.columns.learner'))
                    ->state(fn (AssignmentResult $record): string => MemberResource::learnerName($record->learner)),
                Tables\Columns\TextColumn::make('progress_pct')
                    ->label(__('org.assignments.columns.progress_pct'))
                    ->suffix('%')
                    ->sortable(),
                Tables\Columns\TextColumn::make('result')
                    ->label(__('org.assignments.columns.result'))
                    ->badge()
                    ->state(fn (AssignmentResult $record): string => match (true) {
                        $record->isLate() => 'late',
                        $record->completed_at !== null => 'done',
                        $record->progress_pct > 0 => 'in_progress',
                        default => 'not_started',
                    })
                    ->formatStateUsing(fn (string $state): string => __('org.assignments.result.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        'done' => 'success',
                        'late' => 'warning',
                        'in_progress' => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('completed_at')
                    ->label(__('org.assignments.columns.completed_at'))
                    ->dateTime('d.m.Y H:i', fn (): string => $this->getOwnerRecord() instanceof Assignment
                        ? $this->getOwnerRecord()->organization->timezone
                        : (string) config('app.timezone'))
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('progress_pct', 'desc')
            ->paginated([25, 50, 100]);
    }
}
