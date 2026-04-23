<?php

declare(strict_types=1);

namespace App\Filament\Resources\LessonResource\RelationManagers;

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
            ->headerActions([Actions\CreateAction::make()])
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->defaultSort('word');
    }
}
