<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\WordResource\Pages;
use App\Filament\Resources\WordResource\RelationManagers\WordTranslationsRelationManager;
use App\Models\Word;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Word screen: the host of the per-language translations (WordTranslationsRelationManager).
 * Words are CREATED on the lesson screen or by the import; they are never deleted (docs/03 §8) —
 * a word is hidden with `is_active` instead.
 */
class WordResource extends Resource
{
    protected static ?string $model = Word::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-language';

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 25;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('word')->required()->maxLength(100),
            Forms\Components\TextInput::make('translation')
                ->label('Translation (RU, default)')
                ->required()
                ->maxLength(500)
                ->helperText('The Russian text. Other languages are in the Translations table below.'),
            Forms\Components\Textarea::make('example')->rows(2)->columnSpanFull(),
            Forms\Components\TextInput::make('transcription')->maxLength(100),
            Forms\Components\Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('word')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('translation')->label('RU')->searchable()->limit(30),
                Tables\Columns\TextColumn::make('lesson.stage.course.slug')->label('Course'),
                Tables\Columns\TextColumn::make('lesson.stage.number')->label('Stage'),
                Tables\Columns\TextColumn::make('lesson.number')->label('Lesson'),
                Tables\Columns\TextColumn::make('translations_count')->counts('translations')->label('Languages'),
                Tables\Columns\IconColumn::make('is_active')->boolean()->label('Active'),
            ])
            ->actions([
                Actions\EditAction::make()->label('Translations'),
            ])
            ->defaultSort('id');
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [WordTranslationsRelationManager::class];
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWords::route('/'),
            'edit' => Pages\EditWord::route('/{record}/edit'),
        ];
    }
}
