<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Administration\Services\PanelDataScope;
use App\Filament\Resources\StudentResource\Pages;
use App\Models\Student;
use Filament\Actions;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StudentResource extends Resource
{
    protected static ?string $model = Student::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 30;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('first_name')->label('First name'),
            TextEntry::make('last_name')->label('Last name')->placeholder('—'),
            TextEntry::make('username')->prefix('@')->placeholder('—'),
            TextEntry::make('telegram_user_id')->label('Telegram ID'),
            TextEntry::make('group.title')->label('Group'),
            IconEntry::make('is_active')->boolean(),
            TextEntry::make('last_seen_at')->dateTime()->placeholder('Never'),
            TextEntry::make('repetitions_count')->label('Cards started'),
            TextEntry::make('training_reviews_count')->label('Training reviews'),
            TextEntry::make('exam_answers_count')->label('Exam answers'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('first_name')
                    ->formatStateUsing(fn (Student $record): string => trim($record->first_name.' '.($record->last_name ?? '')))
                    ->label('Name')
                    ->searchable(['first_name', 'last_name']),
                Tables\Columns\TextColumn::make('username')
                    ->prefix('@')
                    ->placeholder('—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('telegram_user_id')->label('TG ID')->copyable()->toggleable(),
                Tables\Columns\TextColumn::make('group.title')
                    ->label('Group')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('last_seen_at')->dateTime()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
                Tables\Filters\SelectFilter::make('telegram_group_id')
                    ->options(fn (): array => app(PanelDataScope::class)
                        ->groups()
                        ->orderBy('title')
                        ->pluck('title', 'id')
                        ->all())
                    ->label('Group'),
            ])
            ->actions([
                Actions\Action::make('toggleActive')
                    ->icon('heroicon-o-power')
                    ->label(fn (Student $record): string => $record->is_active ? 'Deactivate' : 'Activate')
                    ->color(fn (Student $record): string => $record->is_active ? 'danger' : 'success')
                    ->visible(fn (): bool => app(PanelDataScope::class)->isAdmin())
                    ->requiresConfirmation()
                    ->action(fn (Student $record) => $record->update(['is_active' => ! $record->is_active])),
                Actions\ViewAction::make(),
            ])
            ->defaultSort('last_seen_at', 'desc');
    }

    /** @return Builder<Student> */
    public static function getEloquentQuery(): Builder
    {
        return app(PanelDataScope::class)
            ->students()
            ->with('group')
            ->withCount(['repetitions', 'trainingReviews', 'examAnswers']);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStudents::route('/'),
            'view' => Pages\ViewStudent::route('/{record}'),
        ];
    }
}
