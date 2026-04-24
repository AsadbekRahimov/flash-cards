<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\LessonResource\Pages;
use App\Filament\Resources\LessonResource\RelationManagers\WordsRelationManager;
use App\Models\Lesson;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class LessonResource extends Resource
{
    protected static ?string $model = Lesson::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Select::make('stage_id')
                ->relationship('stage', 'title')
                ->required()
                ->searchable(),
            Forms\Components\TextInput::make('number')->numeric()->required(),
            Forms\Components\TextInput::make('title')->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('stage.title')->label('Stage')->sortable(),
                Tables\Columns\TextColumn::make('number')->sortable(),
                Tables\Columns\TextColumn::make('title')->searchable(),
                Tables\Columns\TextColumn::make('words_count')->counts('words')->label('Words'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('stage_id')
                    ->relationship('stage', 'title')
                    ->label('Stage'),
            ])
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->defaultSort('stage_id');
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [WordsRelationManager::class];
    }

    /** @return array<string, \Filament\Resources\Pages\PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLessons::route('/'),
            'create' => Pages\CreateLesson::route('/create'),
            'edit' => Pages\EditLesson::route('/{record}/edit'),
        ];
    }
}
