<?php

declare(strict_types=1);

namespace App\Filament\Resources\LessonResource\RelationManagers;

use App\Domain\Content\Services\LessonContentRefresher;
use App\Domain\Content\Services\WordTranslationWriter;
use App\Filament\Resources\WordResource;
use App\Models\Word;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class WordsRelationManager extends RelationManager
{
    protected static string $relationship = 'words';

    protected static ?string $recordTitleAttribute = 'word';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('word')->required()->maxLength(100),
            Forms\Components\TextInput::make('translation')->required()->maxLength(500),
            Forms\Components\Textarea::make('example')->rows(2)->columnSpanFull(),
            Forms\Components\Select::make('part_of_speech')->options([
                'noun' => 'noun',
                'verb' => 'verb',
                'adjective' => 'adjective',
                'adverb' => 'adverb',
                'pronoun' => 'pronoun',
                'preposition' => 'preposition',
                'conjunction' => 'conjunction',
                'interjection' => 'interjection',
            ]),
            Forms\Components\TextInput::make('transcription')->maxLength(100),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('word')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('translation')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('part_of_speech')->badge()->toggleable(),
                Tables\Columns\TextColumn::make('transcription')->toggleable(isToggledHiddenByDefault: true),
            ])
            // `translation` is the ru text: mirror it into the ru row of word_translations.
            ->headerActions([
                Actions\CreateAction::make()
                    ->after(function (Word $record): void {
                        app(WordTranslationWriter::class)->syncDefaultFromWord($record);
                        app(LessonContentRefresher::class)->wordChanged($record);
                    }),
            ])
            ->actions([
                Actions\EditAction::make()
                    ->after(function (Word $record): void {
                        $textChanged = $record->wasChanged('word');

                        app(WordTranslationWriter::class)->syncDefaultFromWord($record);
                        if ($textChanged) {
                            app(LessonContentRefresher::class)->wordTextChanged($record);
                        }
                    }),
                Actions\Action::make('translations')
                    ->label('Translations')
                    ->icon('heroicon-o-language')
                    ->visible(fn (Word $record): bool => WordResource::canEdit($record))
                    ->url(fn (Word $record): string => WordResource::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort('word');
    }
}
