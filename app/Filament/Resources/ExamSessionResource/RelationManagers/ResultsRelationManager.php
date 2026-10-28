<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExamSessionResource\RelationManagers;

use App\Filament\Resources\Support\AdminNumbers;
use App\Models\ExamAnswer;
use App\Models\ExamResult;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ResultsRelationManager extends RelationManager
{
    protected static string $relationship = 'results';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.exams.results.title');
    }

    public function table(Table $table): Table
    {
        $sessionId = (int) $this->getOwnerRecord()->getKey();

        return $table
            ->modelLabel(__('admin.exams.results.singular'))
            ->pluralModelLabel(__('admin.exams.results.plural'))
            ->emptyStateHeading(__('admin.exams.results.empty'))
            ->modifyQueryUsing(function (Builder $query) use ($sessionId): Builder {
                $timeQuery = ExamAnswer::query()
                    ->selectRaw('COALESCE(SUM(time_spent_ms), 0)')
                    ->whereColumn('exam_answers.learner_id', 'exam_results.learner_id')
                    ->where('exam_answers.exam_session_id', $sessionId);

                return $query
                    ->select('exam_results.*')
                    ->selectSub($timeQuery, 'time_spent_ms');
            })
            ->columns([
                Tables\Columns\TextColumn::make('learner.first_name')
                    ->label(__('admin.exams.results.columns.learner'))
                    ->formatStateUsing(fn (ExamResult $record): string => trim(
                        ($record->learner->first_name ?? '—').' '.($record->learner->last_name ?? ''),
                    ))
                    ->searchable(),
                Tables\Columns\TextColumn::make('total_score')->label(__('admin.exams.results.columns.total_score'))->sortable(),
                Tables\Columns\TextColumn::make('correct_count')->label(__('admin.exams.results.columns.correct_count'))->sortable(),
                Tables\Columns\TextColumn::make('total_count')->label(__('admin.exams.results.columns.total_count')),
                Tables\Columns\TextColumn::make('time_spent_ms')
                    ->label(__('admin.exams.results.columns.time'))
                    ->formatStateUsing(fn (mixed $state): string => __('admin.exams.results.seconds', ['value' => AdminNumbers::decimal((int) $state / 1000, 2)])),
                Tables\Columns\TextColumn::make('rank')->label(__('admin.exams.results.columns.rank'))->sortable(),
            ])
            ->defaultSort('rank');
    }
}
