<?php

declare(strict_types=1);

namespace App\Filament\Resources\StageResource\RelationManagers;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LessonsRelationManager extends RelationManager
{
    protected static string $relationship = 'lessons';

    protected static ?string $recordTitleAttribute = 'title';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.stages.lessons.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('number')->label(__('admin.lessons.fields.number'))->numeric()->required(),
            Forms\Components\TextInput::make('title')->label(__('admin.lessons.fields.title'))->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(mb_strtolower(__('admin.lessons.singular')))
            ->pluralModelLabel(__('admin.lessons.plural'))
            ->emptyStateHeading(__('admin.lessons.empty'))
            ->columns([
                Tables\Columns\TextColumn::make('number')->label(__('admin.lessons.fields.number'))->sortable(),
                Tables\Columns\TextColumn::make('title')->label(__('admin.lessons.fields.title'))->searchable(),
                Tables\Columns\TextColumn::make('words_count')->counts('words')->label(__('admin.lessons.columns.words_count')),
            ])
            ->headerActions([Actions\CreateAction::make()->label(__('admin.lessons.actions.create'))->modalHeading(__('admin.lessons.actions.create'))])
            ->actions([
                Actions\EditAction::make(),
            ])
            ->defaultSort('number');
    }
}
