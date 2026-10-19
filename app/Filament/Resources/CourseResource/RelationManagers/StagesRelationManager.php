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
use Illuminate\Validation\Rules\Unique;

/** Modules (stages) of a course; lessons and words are edited on the stage / lesson screens. */
class StagesRelationManager extends RelationManager
{
    protected static string $relationship = 'stages';

    protected static ?string $title = 'Modules (stages)';

    protected static ?string $recordTitleAttribute = 'title';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('number')
                ->numeric()
                ->required()
                // Stage numbers are unique within a course.
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('course_id', $this->getOwnerRecord()->getKey()),
                ),
            Forms\Components\TextInput::make('title')->required()->maxLength(255),
            Forms\Components\Textarea::make('description')->rows(3)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')->sortable(),
                Tables\Columns\TextColumn::make('title')->searchable(),
                Tables\Columns\TextColumn::make('lessons_count')->counts('lessons')->label('Lessons'),
            ])
            ->headerActions([Actions\CreateAction::make()])
            ->actions([
                Actions\Action::make('lessons')
                    ->label('Lessons')
                    ->icon('heroicon-o-book-open')
                    ->url(fn (Stage $record): string => StageResource::getUrl('edit', ['record' => $record])),
                Actions\EditAction::make(),
            ])
            ->defaultSort('number');
    }
}
