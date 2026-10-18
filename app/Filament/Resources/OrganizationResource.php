<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Localization\Services\LocaleResolver;
use App\Filament\Resources\OrganizationResource\Pages;
use App\Filament\Resources\OrganizationResource\RelationManagers\GroupsRelationManager;
use App\Models\Organization;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Organizations: learning centers that can sponsor Pro for the learners of their groups
 * (docs/12 §5.1). Admin only (OrganizationPolicy). The sponsorship itself is a subscription of the
 * organization ("Grant Pro" on the Subscriptions screen); groups are linked in the relation manager.
 * Only an `active` organization sponsors: suspend it to stop the sponsorship without deleting anything.
 */
class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        $locales = app(LocaleResolver::class)->supported();

        return $schema->components([
            Forms\Components\TextInput::make('name')->required()->maxLength(150),
            Forms\Components\TextInput::make('slug')
                ->required()
                ->maxLength(64)
                ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                ->helperText('Latin lowercase, digits and dashes. Cannot be changed later.')
                ->unique(ignoreRecord: true)
                ->disabledOn('edit'),
            Forms\Components\Select::make('type')
                ->options(['center' => 'Learning center'])
                ->default('center')
                ->required(),
            Forms\Components\Select::make('status')
                ->options(['active' => 'Active', 'suspended' => 'Suspended (no sponsorship)'])
                ->default('active')
                ->required(),
            Forms\Components\TextInput::make('timezone')
                ->required()
                ->default('Asia/Tashkent')
                ->rules(['timezone:all'])
                ->maxLength(64),
            Forms\Components\Select::make('locale')
                ->options(array_combine($locales, array_map(strtoupper(...), $locales)))
                ->default(app(LocaleResolver::class)->default())
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('slug')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('type')->badge()->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('telegram_groups_count')->counts('telegramGroups')->label('Groups'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['active' => 'Active', 'suspended' => 'Suspended']),
            ])
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->defaultSort('name');
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [GroupsRelationManager::class];
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrganizations::route('/'),
            'create' => Pages\CreateOrganization::route('/create'),
            'edit' => Pages\EditOrganization::route('/{record}/edit'),
        ];
    }
}
