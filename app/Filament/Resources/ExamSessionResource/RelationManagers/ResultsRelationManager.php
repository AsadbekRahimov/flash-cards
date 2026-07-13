<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExamSessionResource\RelationManagers;

use App\Models\ExamAnswer;
use App\Models\ExamResult;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ResultsRelationManager extends RelationManager
{
    protected static string $relationship = 'results';

    protected static ?string $title = 'Student results';

    public function table(Table $table): Table
    {
        $sessionId = (int) $this->getOwnerRecord()->getKey();

        return $table
            ->modifyQueryUsing(function (Builder $query) use ($sessionId): Builder {
                $timeQuery = ExamAnswer::query()
                    ->selectRaw('COALESCE(SUM(time_spent_ms), 0)')
                    ->whereColumn('exam_answers.student_id', 'exam_results.student_id')
                    ->where('exam_answers.exam_session_id', $sessionId);

                return $query
                    ->select('exam_results.*')
                    ->selectSub($timeQuery, 'time_spent_ms');
            })
            ->columns([
                Tables\Columns\TextColumn::make('student.first_name')
                    ->label('Student')
                    ->formatStateUsing(fn (ExamResult $record): string => trim(
                        ($record->student->first_name ?? '—').' '.($record->student->last_name ?? ''),
                    ))
                    ->searchable(),
                Tables\Columns\TextColumn::make('total_score')->label('Score')->sortable(),
                Tables\Columns\TextColumn::make('correct_count')->label('Correct')->sortable(),
                Tables\Columns\TextColumn::make('total_count')->label('Answered'),
                Tables\Columns\TextColumn::make('time_spent_ms')
                    ->label('Time')
                    ->formatStateUsing(fn (mixed $state): string => number_format((int) $state / 1000, 2).' s'),
                Tables\Columns\TextColumn::make('rank')->sortable(),
            ])
            ->defaultSort('rank');
    }
}
