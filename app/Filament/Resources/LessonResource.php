<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Administration\Services\PanelDataScope;
use App\Filament\Resources\LessonResource\Pages;
use App\Filament\Resources\LessonResource\RelationManagers\WordsRelationManager;
use App\Models\Lesson;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LessonResource extends Resource
{
    protected static ?string $model = Lesson::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 20;

    /**
     * Teachers do not see the content of learners' private decks.
     *
     * @return Builder<Lesson>
     */
    public static function getEloquentQuery(): Builder
    {
        return app(PanelDataScope::class)->lessons();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Select::make('stage_id')
                ->relationship('stage', 'title')
                ->required()
                ->searchable()
                // Moving a lesson to a stage of another course would leave words_count stale.
                ->disabledOn('edit'),
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
            ])
            ->defaultSort('stage_id');
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [WordsRelationManager::class];
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLessons::route('/'),
            'create' => Pages\CreateLesson::route('/create'),
            'edit' => Pages\EditLesson::route('/{record}/edit'),
        ];
    }
}
