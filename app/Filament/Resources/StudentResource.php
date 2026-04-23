<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\StudentResource\Pages;
use App\Models\Student;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

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
                    ->relationship('group', 'title')
                    ->label('Group'),
            ])
            ->actions([
                Tables\Actions\Action::make('toggleActive')
                    ->icon('heroicon-o-power')
                    ->label(fn (Student $record): string => $record->is_active ? 'Deactivate' : 'Activate')
                    ->color(fn (Student $record): string => $record->is_active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->action(fn (Student $record) => $record->update(['is_active' => ! $record->is_active])),
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('last_seen_at', 'desc');
    }

    /** @return array<string, array<string, string>|class-string> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStudents::route('/'),
            'view' => Pages\ViewStudent::route('/{record}'),
        ];
    }
}
