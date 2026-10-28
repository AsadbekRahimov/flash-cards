<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Administration\Services\PanelDataScope;
use App\Filament\Resources\LessonResource\Pages;
use App\Filament\Resources\LessonResource\RelationManagers\WordsRelationManager;
use App\Models\Lesson;
use App\Models\Stage;
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

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.content');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.lessons.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.lessons.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.lessons.plural');
    }

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
                ->label(__('admin.lessons.fields.stage'))
                ->relationship('stage', 'title')
                // A module without a title is offered as "Модуль N" and found by its number: a LIKE on the title never matches NULL.
                ->getOptionLabelFromRecordUsing(fn (Stage $record): string => StageResource::displayTitle($record))
                ->getSearchResultsUsing(fn (string $search): array => StageResource::searchOptions($search))
                ->required()
                ->searchable()
                // Moving a lesson to a stage of another course would leave words_count stale.
                ->disabledOn('edit'),
            Forms\Components\TextInput::make('number')->label(__('admin.lessons.fields.number'))->numeric()->required(),
            Forms\Components\TextInput::make('title')->label(__('admin.lessons.fields.title'))->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('stage.title')
                    ->label(__('admin.lessons.fields.stage'))
                    ->sortable()
                    ->default(fn (Lesson $record): string => StageResource::displayTitle($record->stage)),
                Tables\Columns\TextColumn::make('number')->label(__('admin.lessons.fields.number'))->sortable(),
                Tables\Columns\TextColumn::make('title')->label(__('admin.lessons.fields.title'))->searchable(),
                Tables\Columns\TextColumn::make('words_count')->counts('words')->label(__('admin.lessons.columns.words_count')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('stage_id')
                    // Only the columns the caption needs: the filter lists every module of the platform.
                    ->relationship('stage', 'title', fn (Builder $query): Builder => $query->select(['stages.id', 'stages.number', 'stages.title'])->orderBy('stages.course_id')->orderBy('stages.number'))
                    ->getOptionLabelFromRecordUsing(fn (Stage $record): string => StageResource::displayTitle($record))
                    ->label(__('admin.lessons.filters.stage')),
            ])
            ->emptyStateHeading(__('admin.lessons.empty'))
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
