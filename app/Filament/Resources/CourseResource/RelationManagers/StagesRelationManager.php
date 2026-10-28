<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseResource\RelationManagers;

use App\Filament\Resources\StageResource;
use App\Models\Stage;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/** Modules (stages) of a course; lessons and words are edited on the stage / lesson screens. */
class StagesRelationManager extends RelationManager
{
    protected static string $relationship = 'stages';

    protected static ?string $recordTitleAttribute = 'title';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.courses.stages.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('number')
                ->label(__('admin.stages.fields.number'))
                ->numeric()
                ->required()
                // Stage numbers are unique within a course.
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('course_id', $this->getOwnerRecord()->getKey()),
                ),
            StageResource::titleField(),
            Forms\Components\Textarea::make('description')->label(__('admin.stages.fields.description'))->rows(3)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (Stage $record): string => StageResource::displayTitle($record))
            ->modelLabel(mb_strtolower(__('admin.stages.singular')))
            ->pluralModelLabel(__('admin.stages.plural'))
            ->emptyStateHeading(__('admin.stages.empty'))
            ->columns([
                Tables\Columns\TextColumn::make('number')->label(__('admin.stages.fields.number'))->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->label(__('admin.stages.fields.title'))
                    ->searchable()
                    ->default(fn (Stage $record): string => StageResource::displayTitle($record)),
                Tables\Columns\TextColumn::make('lessons_count')->counts('lessons')->label(__('admin.stages.columns.lessons_count')),
            ])
            ->headerActions([Actions\CreateAction::make()->label(__('admin.stages.actions.create'))->modalHeading(__('admin.stages.actions.create'))])
            ->actions([
                Actions\Action::make('lessons')
                    ->label(__('admin.stages.actions.lessons'))
                    ->icon('heroicon-o-book-open')
                    ->url(fn (Stage $record): string => StageResource::getUrl('edit', ['record' => $record])),
                Actions\EditAction::make(),
            ])
            ->defaultSort('number');
    }
}
