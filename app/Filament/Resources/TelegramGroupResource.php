<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TelegramGroupResource\Pages;
use App\Models\TelegramGroup;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class TelegramGroupResource extends Resource
{
    protected static ?string $model = TelegramGroup::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Telegram Groups';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('chat_id')->disabled(),
            Forms\Components\TextInput::make('title')->required()->maxLength(255),
            Forms\Components\Select::make('status')
                ->options([
                    'pending' => 'Pending',
                    'active' => 'Active',
                    'disabled' => 'Disabled',
                ])
                ->required(),
            Forms\Components\KeyValue::make('meta')->columnSpanFull(),
            Forms\Components\Select::make('teachers')
                ->relationship('teachers', 'name')
                ->multiple()
                ->preload()
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('chat_id')->copyable()->searchable(),
                Tables\Columns\TextColumn::make('title')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'pending' => 'warning',
                        'disabled' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('students_count')
                    ->counts('students')
                    ->label('Students'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'active' => 'Active',
                    'disabled' => 'Disabled',
                ]),
            ])
            ->actions([
                Actions\Action::make('activate')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (TelegramGroup $record): bool => $record->status !== 'active')
                    ->requiresConfirmation()
                    ->action(fn (TelegramGroup $record) => $record->update(['status' => 'active'])),
                Actions\Action::make('deactivate')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (TelegramGroup $record): bool => $record->status === 'active')
                    ->requiresConfirmation()
                    ->action(fn (TelegramGroup $record) => $record->update(['status' => 'disabled'])),
                Actions\EditAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [];
    }

    /** @return array<string, \Filament\Resources\Pages\PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTelegramGroups::route('/'),
            'edit' => Pages\EditTelegramGroup::route('/{record}/edit'),
        ];
    }
}
