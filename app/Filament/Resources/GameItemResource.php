<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Engagement\Enums\AchievementKey;
use App\Domain\Game\Enums\BoosterEffect;
use App\Domain\Game\Enums\ItemAvailability;
use App\Domain\Game\Enums\ItemKind;
use App\Domain\Game\Enums\ItemSlot;
use App\Domain\Game\Enums\ItemStatus;
use App\Domain\Game\Services\Economy\ShopService;
use App\Domain\Game\Support\GameAssets;
use App\Filament\Resources\GameItemResource\Pages;
use App\Filament\Resources\Support\AdminLabels;
use App\Filament\Resources\Support\AdminTable;
use App\Models\GameItem;
use Closure;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The catalogue of the shop of the game "Караван" (docs/18 §12.10): cosmetics and boosters. Admin only (GameItemPolicy). Items are saved
 * through Eloquent, so `GameItemObserver` moves the version of the cached catalogue on. The key is chosen once (it is the item's name
 * for the Mini App and the seeder); nothing is deleted - "В архив" takes an item off the shelf and leaves it in the inventories.
 */
class GameItemResource extends Resource
{
    protected static ?string $model = GameItem::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?int $navigationSort = 10;

    /** The key of an item: lower-case latin letters, digits and underscores, up to 48 characters. */
    public const KEY_PATTERN = '/\A[a-z0-9][a-z0-9_]{0,47}\z/';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.game');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.game.items.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.game.items.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.game.items.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('key')
                ->label(__('admin.game.items.fields.key'))
                ->helperText(__('admin.game.items.fields.key_help'))
                ->required()
                ->maxLength(48)
                ->regex(self::KEY_PATTERN)
                ->unique(ignoreRecord: true)
                ->validationMessages(['regex' => __('admin.game.items.fields.key_invalid'), 'unique' => __('admin.game.items.fields.key_taken')])
                ->disabledOn('edit'),
            Forms\Components\Select::make('kind')
                ->label(__('admin.game.items.fields.kind'))
                ->options(AdminLabels::options('game.items.kind', array_map(fn (ItemKind $k): string => $k->value, ItemKind::cases())))
                ->default(ItemKind::Cosmetic->value)
                ->required()
                ->live()
                ->native(false)
                ->disabledOn('edit'),
            Forms\Components\Select::make('slot')
                ->label(__('admin.game.items.fields.slot'))
                ->options(AdminLabels::options('game.items.slot', array_map(fn (ItemSlot $s): string => $s->value, ItemSlot::cases())))
                ->required(fn (Get $get): bool => $get('kind') === ItemKind::Cosmetic->value)
                ->visible(fn (Get $get): bool => $get('kind') === ItemKind::Cosmetic->value)
                ->native(false),
            Forms\Components\Select::make('effect')
                ->label(__('admin.game.items.fields.effect'))
                ->options(AdminLabels::options('game.items.effect', array_map(fn (BoosterEffect $e): string => $e->value, BoosterEffect::cases())))
                ->required(fn (Get $get): bool => $get('kind') === ItemKind::Booster->value)
                ->visible(fn (Get $get): bool => $get('kind') === ItemKind::Booster->value)
                ->native(false),
            Forms\Components\TextInput::make('price_coins')
                ->label(__('admin.game.items.fields.price_coins'))
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue(1000000)
                ->default(0)
                ->required()
                ->helperText(fn (?GameItem $record): string => $record !== null && self::givenToEveryone($record)
                    ? __('admin.game.items.fields.price_default_warning')
                    : __('admin.game.items.fields.price_help')),
            Forms\Components\Select::make('availability')
                ->label(__('admin.game.items.fields.availability'))
                ->options(AdminLabels::options('game.items.availability', array_map(fn (ItemAvailability $a): string => $a->value, ItemAvailability::cases())))
                ->default(ItemAvailability::Base->value)
                ->required()
                ->live()
                ->native(false),
            Forms\Components\Select::make('achievement_key')
                ->label(__('admin.game.items.fields.achievement_key'))
                ->options(AdminLabels::options('game.items.achievement', array_map(fn (AchievementKey $k): string => $k->value, AchievementKey::cases())))
                ->required(fn (Get $get): bool => $get('availability') === ItemAvailability::Achievement->value)
                ->visible(fn (Get $get): bool => $get('availability') === ItemAvailability::Achievement->value)
                ->native(false),
            Forms\Components\TextInput::make('sort')
                ->label(__('admin.game.items.fields.sort'))
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue(32000)
                ->default(0)
                ->required(),
            Forms\Components\TextInput::make('title.ru')->label(__('admin.game.items.fields.title_ru'))->required()->maxLength(80),
            Forms\Components\TextInput::make('title.uz')->label(__('admin.game.items.fields.title_uz'))->required()->maxLength(80),
            Forms\Components\Textarea::make('description.ru')->label(__('admin.game.items.fields.description_ru'))->rows(2)->maxLength(300),
            Forms\Components\Textarea::make('description.uz')->label(__('admin.game.items.fields.description_uz'))->rows(2)->maxLength(300),
            Forms\Components\Select::make('asset')
                ->label(__('admin.game.items.fields.asset'))
                ->helperText(__('admin.game.items.fields.asset_help'))
                ->options(array_combine(GameAssets::NAMES, GameAssets::NAMES))
                ->rules([
                    fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (is_string($value) && $value !== '' && ! GameAssets::has($value)) {
                            $fail(__('admin.game.items.fields.asset_invalid'));
                        }
                    },
                ])
                ->native(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return AdminTable::bounded($table)
            ->columns([
                Tables\Columns\TextColumn::make('key')->label(__('admin.game.items.fields.key'))->fontFamily('mono')->searchable(),
                Tables\Columns\TextColumn::make('title.ru')->label(__('admin.game.items.fields.title_ru')),
                Tables\Columns\TextColumn::make('kind')
                    ->label(__('admin.game.items.fields.kind'))
                    ->badge()
                    ->formatStateUsing(fn (ItemKind $state): string => AdminLabels::value('game.items.kind', $state->value)),
                Tables\Columns\TextColumn::make('slot')
                    ->label(__('admin.game.items.fields.slot'))
                    ->formatStateUsing(fn (?ItemSlot $state): string => AdminLabels::value('game.items.slot', $state?->value))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('effect')
                    ->label(__('admin.game.items.fields.effect'))
                    ->formatStateUsing(fn (?BoosterEffect $state): string => AdminLabels::value('game.items.effect', $state?->value))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('price_coins')->label(__('admin.game.items.fields.price_coins'))->numeric()->sortable(),
                Tables\Columns\TextColumn::make('availability')
                    ->label(__('admin.game.items.fields.availability'))
                    ->badge()
                    ->formatStateUsing(fn (ItemAvailability $state): string => AdminLabels::value('game.items.availability', $state->value)),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.game.items.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (ItemStatus $state): string => AdminLabels::value('game.items.status', $state->value))
                    ->color(fn (ItemStatus $state): string => match ($state) {
                        ItemStatus::Published => 'success',
                        ItemStatus::Draft => 'warning',
                        ItemStatus::Archived => 'gray',
                    }),
                Tables\Columns\TextColumn::make('sort')->label(__('admin.game.items.fields.sort'))->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('kind')
                    ->label(__('admin.game.items.fields.kind'))
                    ->options(AdminLabels::options('game.items.kind', array_map(fn (ItemKind $k): string => $k->value, ItemKind::cases()))),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.game.items.fields.status'))
                    ->options(AdminLabels::options('game.items.status', array_map(fn (ItemStatus $s): string => $s->value, ItemStatus::cases()))),
                Tables\Filters\SelectFilter::make('availability')
                    ->label(__('admin.game.items.fields.availability'))
                    ->options(AdminLabels::options('game.items.availability', array_map(fn (ItemAvailability $a): string => $a->value, ItemAvailability::cases()))),
            ])
            ->emptyStateHeading(__('admin.game.items.empty'))
            ->actions([
                Actions\Action::make('publish')
                    ->label(__('admin.game.items.actions.publish'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (GameItem $record): bool => $record->status !== ItemStatus::Published)
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->action(fn (GameItem $record) => self::setStatus($record, ItemStatus::Published)),
                Actions\Action::make('archive')
                    ->label(__('admin.game.items.actions.archive'))
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->visible(fn (GameItem $record): bool => $record->status !== ItemStatus::Archived)
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.game.items.actions.archive_help'))
                    ->action(fn (GameItem $record) => self::setStatus($record, ItemStatus::Archived)),
                Actions\EditAction::make(),
            ])
            ->defaultSort('sort');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGameItems::route('/'),
            'create' => Pages\CreateGameItem::route('/create'),
            'edit' => Pages\EditGameItem::route('/{record}/edit'),
        ];
    }

    /** Saved through Eloquent: the observer moves the version of the cached catalogue on after the commit. */
    public static function setStatus(GameItem $item, ItemStatus $status): void
    {
        $item->update(['status' => $status]);
    }

    /**
     * The shape of a saved item: a cosmetic has a slot and no effect, a booster an effect and no slot; the achievement is kept for
     * the availability `achievement` only. (A field the form hid can still carry a value from before the kind was switched.)
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data, ItemKind $kind): array
    {
        if ($kind === ItemKind::Cosmetic) {
            $data['effect'] = null;
        } else {
            $data['slot'] = null;
        }

        if (($data['availability'] ?? null) !== ItemAvailability::Achievement->value) {
            $data['achievement_key'] = null;
        }

        return $data;
    }

    /** Whether the item is given to everybody (cosmetic, `base`, price 0): its price is the price of the default wardrobe. */
    public static function givenToEveryone(GameItem $item): bool
    {
        return ShopService::isDefault($item->kind->value, $item->availability, $item->price_coins);
    }
}
