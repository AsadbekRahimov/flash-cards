<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources;

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Game\Enums\ClassCaravanStatus;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\Class\ClassCaravanService;
use App\Domain\Game\Services\GameAccess;
use App\Domain\Organizations\Services\OrgTenantScope;
use App\Domain\Telegram\Services\ClassCaravanMessenger;
use App\Filament\Org\Resources\ClassCaravanResource\Pages;
use App\Filament\Resources\Support\AdminTable;
use App\Models\ClassCaravan;
use App\Models\TelegramGroup;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The caravans of the classes of the center (docs/18 §12.10, §12.11, G2.9b): the owner sees every group of the center, a teacher their own
 * ({@see OrgTenantScope::classCaravans()}). Start and cancel go through {@see ClassCaravanService} (the
 * rights, the center with a live subscription, one caravan per group are its decisions) and say the same in the chat of the group as the bot's
 * `/start_caravan` and `/cancel_caravan` ({@see ClassCaravanMessenger}). The view page has the table of contributions. The page does not exist
 * while the game is closed (`GAME_ACCESS=off`).
 */
class ClassCaravanResource extends OrgResource
{
    protected static ?string $model = ClassCaravan::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    protected static ?int $navigationSort = 55;

    protected static ?string $slug = 'class-caravans';

    public static function getNavigationLabel(): string
    {
        return __('org.nav.caravans');
    }

    public static function getModelLabel(): string
    {
        return __('org.caravans.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('org.caravans.plural');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return ! app(GameAccess::class)->isOff() && parent::shouldRegisterNavigation();
    }

    /** @return Builder<ClassCaravan> */
    public static function getEloquentQuery(): Builder
    {
        return static::scope()->classCaravans(static::user(), static::tenant())
            ->with(['group', 'course:id,slug,title'])
            ->withCount('members as members_count');
    }

    /**
     * The groups the user may start a caravan in (their groups of the center that are active).
     *
     * @return array<int, string>
     */
    public static function groupOptions(): array
    {
        $ids = static::scope()->caravanGroupIds(static::user(), static::tenant());

        /** @var array<int, string> $options */
        $options = static::scope()->groups(static::user(), static::tenant())
            ->whereIn('telegram_groups.id', $ids)
            ->where('telegram_groups.status', 'active')
            ->orderBy('telegram_groups.title')
            ->pluck('telegram_groups.title', 'telegram_groups.id')
            ->all();

        return $options;
    }

    public static function table(Table $table): Table
    {
        $timezone = static::tenant()->timezone;
        $catalog = app(CourseCatalogService::class);

        // Page sizes 10/25/50/100 and nothing else (docs/20 §1.6): a forged $tableRecordsPerPage gets the default, not every row.
        return AdminTable::bounded($table)
            ->columns([
                Tables\Columns\TextColumn::make('group.title')->label(__('org.caravans.columns.group'))->limit(32),
                Tables\Columns\TextColumn::make('course')
                    ->label(__('org.caravans.columns.course'))
                    ->state(fn (ClassCaravan $record): string => $catalog->title($record->course, app()->getLocale()))
                    ->limit(32),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('org.caravans.columns.status'))
                    ->badge()
                    ->state(fn (ClassCaravan $record): string => $record->status->value)
                    ->formatStateUsing(fn (string $state): string => __('org.caravans.status.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        ClassCaravanStatus::Active->value => 'success',
                        ClassCaravanStatus::Finished->value => 'gray',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('stars_total')
                    ->label(__('org.caravans.columns.progress'))
                    ->state(fn (ClassCaravan $record): string => __('org.caravans.progress_of', ['stars' => $record->stars_total, 'target' => $record->target_stars]))
                    ->sortable(),
                Tables\Columns\TextColumn::make('starts_at')
                    ->label(__('org.caravans.columns.window'))
                    ->state(fn (ClassCaravan $record): string => $record->starts_at->setTimezone($timezone)->format('d.m.Y H:i').' — '.$record->ends_at->setTimezone($timezone)->format('d.m.Y H:i'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('boss')
                    ->label(__('org.caravans.columns.boss'))
                    ->badge()
                    ->state(fn (ClassCaravan $record): string => static::bossState($record))
                    ->formatStateUsing(fn (string $state): string => __('org.caravans.boss.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        'defeated' => 'success',
                        'ready' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('members_count')->label(__('org.caravans.columns.members')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('org.caravans.columns.status'))
                    ->options([
                        ClassCaravanStatus::Active->value => __('org.caravans.status.active'),
                        ClassCaravanStatus::Finished->value => __('org.caravans.status.finished'),
                        ClassCaravanStatus::Canceled->value => __('org.caravans.status.canceled'),
                    ]),
                Tables\Filters\SelectFilter::make('telegram_group_id')
                    ->label(__('org.caravans.columns.group'))
                    ->options(fn (): array => static::scope()->groups(static::user(), static::tenant())->orderBy('title')->pluck('title', 'id')->all()),
            ])
            ->headerActions([
                Actions\Action::make('start')
                    ->label(__('org.caravans.actions.start'))
                    ->icon('heroicon-o-flag')
                    ->authorize(fn (): bool => static::can('create'))
                    ->modalDescription(__('org.caravans.actions.start_help'))
                    ->schema([
                        Forms\Components\Select::make('telegram_group_id')
                            ->label(__('org.caravans.fields.group'))
                            ->helperText(__('org.caravans.fields.group_help'))
                            ->options(fn (): array => static::groupOptions())
                            ->required(),
                    ])
                    ->action(fn (array $data) => static::start((int) $data['telegram_group_id'])),
            ])
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\Action::make('cancel')
                    ->label(__('org.caravans.actions.cancel'))
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('org.caravans.actions.cancel_confirm'))
                    ->visible(fn (ClassCaravan $record): bool => $record->isActive())
                    ->authorize(fn (ClassCaravan $record): bool => static::can('cancel', $record))
                    ->action(fn (ClassCaravan $record) => static::cancel($record)),
            ])
            ->defaultSort('starts_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        $timezone = static::tenant()->timezone;

        return $schema->components([
            Infolists\Components\TextEntry::make('group.title')->label(__('org.caravans.columns.group')),
            Infolists\Components\TextEntry::make('course')
                ->label(__('org.caravans.columns.course'))
                ->state(fn (ClassCaravan $record): string => app(CourseCatalogService::class)->title($record->course, app()->getLocale())),
            Infolists\Components\TextEntry::make('status')
                ->label(__('org.caravans.columns.status'))
                ->badge()
                ->state(fn (ClassCaravan $record): string => $record->status->value)
                ->formatStateUsing(fn (string $state): string => __('org.caravans.status.'.$state)),
            Infolists\Components\TextEntry::make('stars_total')
                ->label(__('org.caravans.columns.progress'))
                ->state(fn (ClassCaravan $record): string => __('org.caravans.progress_of', ['stars' => $record->stars_total, 'target' => $record->target_stars])),
            Infolists\Components\TextEntry::make('starts_at')
                ->label(__('org.caravans.columns.window'))
                ->state(fn (ClassCaravan $record): string => $record->starts_at->setTimezone($timezone)->format('d.m.Y H:i').' — '.$record->ends_at->setTimezone($timezone)->format('d.m.Y H:i')),
            Infolists\Components\TextEntry::make('boss')
                ->label(__('org.caravans.columns.boss'))
                ->badge()
                ->state(fn (ClassCaravan $record): string => static::bossState($record))
                ->formatStateUsing(fn (string $state): string => __('org.caravans.boss.'.$state)),
            Infolists\Components\RepeatableEntry::make('standings')
                ->label(__('org.caravans.members.heading'))
                ->placeholder(__('org.caravans.members.empty'))
                ->columnSpanFull()
                ->state(fn (ClassCaravan $record): array => app(ClassCaravanService::class)->standings($record))
                ->schema([
                    Infolists\Components\TextEntry::make('name')->label(__('org.caravans.members.name')),
                    Infolists\Components\TextEntry::make('stars')->label(__('org.caravans.members.stars')),
                    Infolists\Components\TextEntry::make('runs')->label(__('org.caravans.members.runs')),
                    Infolists\Components\TextEntry::make('best_score')->label(__('org.caravans.members.best_score')),
                    Infolists\Components\TextEntry::make('boss_won')
                        ->label(__('org.caravans.members.boss_won'))
                        ->formatStateUsing(fn (mixed $state): string => __($state ? 'org.caravans.members.yes' : 'org.caravans.members.no')),
                ])
                ->columns(5),
        ]);
    }

    /** `defeated` / `ready` (the target is reached, the class may fight it) / `locked`. */
    public static function bossState(ClassCaravan $caravan): string
    {
        return match (true) {
            $caravan->boss_defeated_at !== null => 'defeated',
            $caravan->boss_unlocked_at !== null => 'ready',
            default => 'locked',
        };
    }

    /** Starts a caravan in a group of the user and says so in the chat of the group (the same message as `/start_caravan`). */
    public static function start(int $groupId): void
    {
        $user = static::user();
        $tenant = static::tenant();

        // A forged id of a group the user does not teach, or of another center, is told apart from nothing else.
        $group = in_array($groupId, static::scope()->caravanGroupIds($user, $tenant), true)
            ? static::scope()->groups($user, $tenant)->whereKey($groupId)->first()
            : null;
        if (! $group instanceof TelegramGroup) {
            Notification::make()->title(__('org.caravans.errors.foreign_group'))->danger()->send();

            return;
        }

        $messenger = app(ClassCaravanMessenger::class);

        try {
            $caravan = app(ClassCaravanService::class)->start($group, $user);
        } catch (GameException $e) {
            Notification::make()->title($messenger->refusalText($e, $group))->danger()->send();

            return;
        }

        $delivered = $messenger->announceStarted($caravan, $group, $user);

        $delivered
            ? Notification::make()->title(__('org.caravans.notifications.started'))->success()->send()
            : Notification::make()->title(__('org.caravans.notifications.started_not_delivered'))->danger()->send();
    }

    /** Cancels a caravan on its way; the chat of the group is told (no summary follows). */
    public static function cancel(ClassCaravan $caravan): void
    {
        $wasActive = $caravan->isActive();
        $messenger = app(ClassCaravanMessenger::class);

        try {
            $canceled = app(ClassCaravanService::class)->cancel($caravan, static::user());
        } catch (GameException $e) {
            $group = $caravan->group;
            Notification::make()->title($group instanceof TelegramGroup ? $messenger->refusalText($e, $group) : $e->getMessage())->danger()->send();

            return;
        }

        if ($wasActive && $canceled->status === ClassCaravanStatus::Canceled && $caravan->group instanceof TelegramGroup) {
            $messenger->announceCanceled($caravan->group);
        }

        Notification::make()->title(__('org.caravans.notifications.canceled'))->success()->send();
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClassCaravans::route('/'),
            'view' => Pages\ViewClassCaravan::route('/{record}'),
        ];
    }
}
