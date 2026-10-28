<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExamSessionResource\RelationManagers;

use App\Models\ExamAnswer;
use App\Models\ExamSession;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AnswersRelationManager extends RelationManager
{
    protected static string $relationship = 'answers';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.exams.answers.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('admin.exams.answers.singular'))
            ->pluralModelLabel(__('admin.exams.answers.plural'))
            ->emptyStateHeading(__('admin.exams.answers.empty'))
            ->columns([
                Tables\Columns\TextColumn::make('learner.first_name')
                    ->label(__('admin.exams.answers.columns.learner'))
                    ->formatStateUsing(fn (ExamAnswer $record): string => trim(
                        ($record->learner->first_name ?? '—').' '.($record->learner->last_name ?? ''),
                    ))
                    ->searchable(),
                Tables\Columns\TextColumn::make('word.word')
                    ->label(__('admin.exams.answers.columns.word'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('selected_translation')
                    ->label(__('admin.exams.answers.columns.selected'))
                    ->placeholder(__('admin.exams.answers.timeout'))
                    ->wrap(),
                Tables\Columns\TextColumn::make('correct_translation')
                    ->label(__('admin.exams.answers.columns.correct'))
                    ->state(fn (ExamAnswer $record): string => $this->correctTranslation($record))
                    ->wrap(),
                Tables\Columns\IconColumn::make('is_correct')->boolean()->label(__('admin.exams.answers.columns.is_correct')),
                Tables\Columns\TextColumn::make('score')->label(__('admin.exams.answers.columns.score'))->sortable(),
                Tables\Columns\TextColumn::make('time_spent_ms')->label(__('admin.exams.answers.columns.time_spent_ms'))->sortable(),
                Tables\Columns\TextColumn::make('answered_at')->label(__('admin.exams.answers.columns.answered_at'))->dateTime()->sortable(),
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
