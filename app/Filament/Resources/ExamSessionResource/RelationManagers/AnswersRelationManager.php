<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExamSessionResource\RelationManagers;

use App\Models\ExamAnswer;
use App\Models\ExamSession;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class AnswersRelationManager extends RelationManager
{
    protected static string $relationship = 'answers';

    protected static ?string $title = 'Per-word answers';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('student.first_name')
                    ->label('Student')
                    ->formatStateUsing(fn (ExamAnswer $record): string => trim(
                        ($record->student->first_name ?? '—').' '.($record->student->last_name ?? ''),
                    ))
                    ->searchable(),
                Tables\Columns\TextColumn::make('word.word')
                    ->label('Word')
                    ->searchable(),
                Tables\Columns\TextColumn::make('selected_translation')
                    ->label('Selected')
                    ->placeholder('Timeout')
                    ->wrap(),
                Tables\Columns\TextColumn::make('correct_translation')
                    ->label('Correct')
                    ->state(fn (ExamAnswer $record): string => $this->correctTranslation($record))
                    ->wrap(),
                Tables\Columns\IconColumn::make('is_correct')->boolean()->label('Correct?'),
                Tables\Columns\TextColumn::make('score')->sortable(),
                Tables\Columns\TextColumn::make('time_spent_ms')->label('Time, ms')->sortable(),
                Tables\Columns\TextColumn::make('answered_at')->dateTime()->sortable(),
            ])
            ->defaultSort('answered_at');
    }

    private function correctTranslation(ExamAnswer $answer): string
    {
        $owner = $this->getOwnerRecord();
        if (! $owner instanceof ExamSession) {
            return '—';
        }

        $questions = $owner->config['questions'] ?? [];
        if (! is_array($questions)) {
            return '—';
        }

        foreach ($questions as $question) {
            if (
                is_array($question)
                && (int) ($question['word_id'] ?? 0) === (int) $answer->word_id
            ) {
                return (string) ($question['correct_translation'] ?? '—');
            }
        }

        return '—';
    }
}
