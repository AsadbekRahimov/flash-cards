<?php

declare(strict_types=1);

namespace App\Filament\Resources\LessonResource\RelationManagers;

use App\Domain\Content\Services\LessonContentRefresher;
use App\Domain\Content\Services\WordTranslationWriter;
use App\Filament\Resources\Support\AdminLabels;
use App\Filament\Resources\WordResource;
use App\Models\Word;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class WordsRelationManager extends RelationManager
{
    protected static string $relationship = 'words';

    protected static ?string $recordTitleAttribute = 'word';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.lessons.words.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('word')->label(__('admin.words.fields.word'))->required()->maxLength(100),
            Forms\Components\TextInput::make('translation')->label(__('admin.words.fields.translation'))->required()->maxLength(500),
            Forms\Components\Textarea::make('example')->label(__('admin.words.fields.example'))->rows(2)->columnSpanFull(),
            Forms\Components\Select::make('part_of_speech')
                ->label(__('admin.words.fields.part_of_speech'))
                ->options(AdminLabels::options('words.part_of_speech', ['noun', 'verb', 'adjective', 'adverb', 'pronoun', 'preposition', 'conjunction', 'interjection'])),
            Forms\Components\TextInput::make('transcription')->label(__('admin.words.fields.transcription'))->maxLength(100),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(mb_strtolower(__('admin.words.singular')))
            ->pluralModelLabel(__('admin.words.plural'))
            ->emptyStateHeading(__('admin.words.empty'))
            ->columns([
                Tables\Columns\TextColumn::make('word')->label(__('admin.words.fields.word'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('translation')->label(__('admin.words.columns.translation'))->searchable()->limit(40),
                Tables\Columns\TextColumn::make('part_of_speech')
                    ->label(__('admin.words.fields.part_of_speech'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => AdminLabels::value('words.part_of_speech', $state))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('transcription')->label(__('admin.words.fields.transcription'))->toggleable(isToggledHiddenByDefault: true),
            ])
            // `translation` is the ru text: mirror it into the ru row of word_translations.
            ->headerActions([
                Actions\CreateAction::make()
                    ->label(__('admin.lessons.words.create'))
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
                    ->label(__('admin.lessons.words.translations'))
                    ->icon('heroicon-o-language')
                    ->visible(fn (Word $record): bool => WordResource::canEdit($record))
                    ->url(fn (Word $record): string => WordResource::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort('word');
    }
}
