<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExamSessionResource\RelationManagers;

use App\Filament\Resources\Support\AdminTable;
use App\Models\ExamAnswer;
use App\Models\ExamSession;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AnswersRelationManager extends RelationManager
{
    protected static string $relationship = 'answers';

    /**
     * The correct translation by word id, read from the answer key of the session once per request - not searched for in
     * the whole key for every row of the page.
     *
     * @var array<int, string>|null
     */
    private ?array $correctTranslations = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.exams.answers.title');
    }

    public function table(Table $table): Table
    {
        return AdminTable::bounded($table)
            ->modelLabel(__('admin.exams.answers.singular'))
            ->pluralModelLabel(__('admin.exams.answers.plural'))
            ->emptyStateHeading(__('admin.exams.answers.empty'))
            // The learner and the word are read for a name and a spelling only (docs/20 §1.4).
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['learner:id,first_name,last_name', 'word:id,word']))
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
        return $this->correctTranslations()[(int) $answer->word_id] ?? '—';
    }

    /** @return array<int, string> word id => the correct translation; the first question of a word wins */
    private function correctTranslations(): array
    {
        if ($this->correctTranslations !== null) {
            return $this->correctTranslations;
        }

        $owner = $this->getOwnerRecord();
        $questions = $owner instanceof ExamSession ? ($owner->config['questions'] ?? []) : [];
        $translations = [];

        if (is_array($questions)) {
            foreach ($questions as $question) {
                if (! is_array($question)) {
                    continue;
                }

                $translations[(int) ($question['word_id'] ?? 0)] ??= (string) ($question['correct_translation'] ?? '—');
            }
        }

        return $this->correctTranslations = $translations;
    }
}
