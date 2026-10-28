<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Administration\Services\PanelDataScope;
use App\Filament\Resources\StageResource\Pages;
use App\Filament\Resources\StageResource\RelationManagers\LessonsRelationManager;
use App\Models\Course;
use App\Models\Stage;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class StageResource extends Resource
{
    protected static ?string $model = Stage::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.content');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.stages.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.stages.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.stages.plural');
    }

    /**
     * Teachers do not see the content of learners' private decks.
     *
     * @return Builder<Stage>
     */
    public static function getEloquentQuery(): Builder
    {
        return app(PanelDataScope::class)->stages();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Select::make('course_id')
                ->label(__('admin.stages.fields.course'))
                ->relationship('course', 'slug')
                ->default(fn (): ?int => Course::query()
                    ->where('slug', (string) config('content.default_course_slug'))
                    ->value('id'))
                ->required()
                ->live()
                ->preload()
                // Moving a stage to another course would leave both courses' words_count stale.
                ->disabledOn('edit'),
            Forms\Components\TextInput::make('number')
                ->label(__('admin.stages.fields.number'))
                ->numeric()
                ->required()
                // Stage numbers are unique within a course, not globally.
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('course_id', $get('course_id')),
                ),
            Forms\Components\TextInput::make('title')->label(__('admin.stages.fields.title'))->required()->maxLength(255),
            Forms\Components\Textarea::make('description')->label(__('admin.stages.fields.description'))->rows(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('course.slug')->label(__('admin.stages.fields.course'))->sortable(),
                Tables\Columns\TextColumn::make('number')->label(__('admin.stages.fields.number'))->sortable(),
                Tables\Columns\TextColumn::make('title')->label(__('admin.stages.fields.title'))->searchable(),
                Tables\Columns\TextColumn::make('lessons_count')->counts('lessons')->label(__('admin.stages.columns.lessons_count')),
                Tables\Columns\TextColumn::make('created_at')->label(__('admin.stages.columns.created_at'))->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateHeading(__('admin.stages.empty'))
            ->actions([
                Actions\EditAction::make(),
            ])
            ->defaultSort('number');
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [LessonsRelationManager::class];
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStages::route('/'),
            'create' => Pages\CreateStage::route('/create'),
            'edit' => Pages\EditStage::route('/{record}/edit'),
        ];
    }
}
