<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Localization\Services\LocaleResolver;
use App\Filament\Resources\OrganizationResource\Pages;
use App\Filament\Resources\OrganizationResource\RelationManagers\GroupsRelationManager;
use App\Filament\Resources\OrganizationResource\RelationManagers\InvitesRelationManager;
use App\Filament\Resources\OrganizationResource\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Support\AdminLabels;
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
 * Invite links and members (seats, waiting list, remove / add) are managed in their relation managers (docs/13 §6.2).
 */
class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static ?int $navigationSort = 30;

    /** The labels are several words long: Filament's title-casing ("Учебный Центр") is wrong in Russian. */
    protected static bool $hasTitleCaseModelLabel = false;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.billing');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.organizations.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.organizations.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.organizations.plural');
    }

    public static function form(Schema $schema): Schema
    {
        $locales = app(LocaleResolver::class)->supported();

        return $schema->components([
            Forms\Components\TextInput::make('name')->label(__('admin.organizations.fields.name'))->required()->maxLength(150),
            Forms\Components\TextInput::make('slug')
                ->label(__('admin.organizations.fields.slug'))
                ->required()
                ->maxLength(64)
                ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                ->helperText(__('admin.organizations.fields.slug_help'))
                ->unique(ignoreRecord: true)
                ->disabledOn('edit'),
            Forms\Components\Select::make('type')
                ->label(__('admin.organizations.fields.type'))
                ->options(AdminLabels::options('organizations.type', ['center']))
                ->default('center')
                ->required(),
            Forms\Components\Select::make('status')
                ->label(__('admin.organizations.fields.status'))
                ->options([
                    'active' => AdminLabels::value('organizations.status', 'active'),
                    'suspended' => __('admin.organizations.fields.status_suspended'),
                ])
                ->default('active')
                ->required(),
            Forms\Components\TextInput::make('timezone')
                ->label(__('admin.organizations.fields.timezone'))
                ->required()
                ->default('Asia/Tashkent')
                ->rules(['timezone:all'])
                ->maxLength(64),
            Forms\Components\Select::make('locale')
                ->label(__('admin.organizations.fields.locale'))
                ->options(array_combine($locales, array_map(strtoupper(...), $locales)))
                ->default(app(LocaleResolver::class)->default())
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('admin.organizations.fields.name'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('slug')->label(__('admin.organizations.fields.slug'))->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('admin.organizations.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('organizations.type', $state))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.organizations.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('organizations.status', $state))
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('telegram_groups_count')->counts('telegramGroups')->label(__('admin.organizations.fields.groups_count')),
                Tables\Columns\TextColumn::make('created_at')->label(__('admin.organizations.fields.created_at'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.organizations.fields.status'))
                    ->options(AdminLabels::options('organizations.status', ['active', 'suspended'])),
            ])
            ->emptyStateHeading(__('admin.organizations.empty'))
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make()->modalHeading(__('admin.organizations.actions.delete_heading'))->authorizationTooltip()->authorizationNotification(),
            ])
            ->defaultSort('name');
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [GroupsRelationManager::class, InvitesRelationManager::class, MembersRelationManager::class];
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
