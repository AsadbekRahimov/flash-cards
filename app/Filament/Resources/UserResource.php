<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\Support\AdminLabels;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.administration');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.users.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.users.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.users.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')
                ->label(__('admin.users.fields.name'))
                ->required()
                ->maxLength(100),
            Forms\Components\TextInput::make('email')
                ->label(__('admin.users.fields.email'))
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(150),
            Forms\Components\TextInput::make('password')
                ->label(__('admin.users.fields.password'))
                ->password()
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->required(fn (string $operation): bool => $operation === 'create')
                ->minLength(8),
            Forms\Components\TextInput::make('telegram_user_id')
                ->label(__('admin.users.fields.telegram_user_id'))
                ->numeric()
                ->unique(ignoreRecord: true)
                ->helperText(__('admin.users.fields.telegram_user_id_help')),
            Forms\Components\Select::make('roles')
                ->label(__('admin.users.fields.roles'))
                ->multiple()
                ->relationship('roles', 'name')
                // The option value stays the role name; only the caption is translated.
                ->options(Role::pluck('name', 'name')->map(fn (string $name): string => AdminLabels::value('users.roles', $name))->all())
                ->preload()
                ->required(),
            Forms\Components\Toggle::make('is_active')->label(__('admin.users.fields.is_active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('admin.users.fields.name'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->label(__('admin.users.fields.email'))->searchable()->copyable(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->badge()
                    ->separator(',')
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('users.roles', $state))
                    ->label(__('admin.users.fields.roles')),
                Tables\Columns\TextColumn::make('telegram_user_id')
                    ->label(__('admin.users.fields.telegram_user_id'))
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->label(__('admin.users.fields.is_active'))->boolean(),
                Tables\Columns\TextColumn::make('last_login_at')
                    ->label(__('admin.users.fields.last_login_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('admin.users.fields.created_at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label(__('admin.users.filters.is_active')),
                Tables\Filters\SelectFilter::make('roles')
                    ->label(__('admin.users.filters.role'))
                    ->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Role $record): string => AdminLabels::value('users.roles', $record->name)),
            ])
            ->emptyStateHeading(__('admin.users.empty'))
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()->modalHeading(__('admin.users.actions.delete_selected')),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('roles');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
