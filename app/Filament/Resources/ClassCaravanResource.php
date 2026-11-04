<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Game\Enums\ClassCaravanStatus;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\Class\ClassCaravanService;
use App\Domain\Telegram\Services\ClassCaravanMessenger;
use App\Filament\Resources\ClassCaravanResource\Pages;
use App\Filament\Resources\Support\AdminLabels;
use App\Filament\Resources\Support\AdminTable;
use App\Models\ClassCaravan;
use App\Models\Course;
use App\Models\Organization;
use App\Models\TelegramGroup;
use App\Models\User;
use Filament\Actions;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The class caravans of the game "Караван" (docs/18 §12.10, G2.11 part 2): read-only, admin only (ClassCaravanPolicy), whatever the center.
 * The only write is "Отменить" (confirmation): {@see ClassCaravanService::cancel()} - for an admin who does not teach the group the reason
 * is `admin` - and the chat of the group is told, as `/cancel_caravan` and the cabinet of the center tell it. The list pages without counting
 * its rows; the page of a caravan has the table of contributions (`standings()`, first names only).
 */
class ClassCaravanResource extends Resource
{
    protected static ?string $model = ClassCaravan::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.game');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.game.caravans.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.game.caravans.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.game.caravans.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return Builder<ClassCaravan> */
    public static function getEloquentQuery(): Builder
    {
        return ClassCaravan::query()
            ->with(['group', 'organization:id,name', 'course:id,slug']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('group.title')->label(__('admin.game.caravans.fields.group')),
            TextEntry::make('organization.name')->label(__('admin.game.caravans.fields.organization'))->placeholder('—'),
            TextEntry::make('course')->label(__('admin.game.caravans.fields.course'))->state(fn (ClassCaravan $record): string => self::courseName($record)),
            TextEntry::make('status')
                ->label(__('admin.game.caravans.fields.status'))
                ->badge()
                ->state(fn (ClassCaravan $record): string => $record->status->value)
                ->formatStateUsing(fn (string $state): string => AdminLabels::value('game.caravans.status', $state)),
            TextEntry::make('stars_total')->label(__('admin.game.caravans.fields.progress'))->state(fn (ClassCaravan $record): string => self::progress($record)),
            TextEntry::make('runs_total')->label(__('admin.game.caravans.fields.runs_total')),
            TextEntry::make('starts_at')->label(__('admin.game.caravans.fields.window'))->state(fn (ClassCaravan $record): string => self::window($record)),
            TextEntry::make('boss')
                ->label(__('admin.game.caravans.fields.boss'))
                ->badge()
                ->state(fn (ClassCaravan $record): string => self::bossState($record))
                ->formatStateUsing(fn (string $state): string => AdminLabels::value('game.caravans.boss', $state)),
            TextEntry::make('summary_posted_at')->label(__('admin.game.caravans.fields.summary_posted_at'))->dateTime()->placeholder(__('admin.game.caravans.summary_none')),
            TextEntry::make('finished_at')->label(__('admin.game.caravans.fields.finished_at'))->dateTime()->placeholder('—'),
            RepeatableEntry::make('standings')
                ->label(__('admin.game.caravans.fields.standings'))
                ->placeholder(__('admin.game.caravans.standings_empty'))
                ->columnSpanFull()
                ->state(fn (?ClassCaravan $record): array => $record === null ? [] : app(ClassCaravanService::class)->standings($record))
                ->schema([
                    TextEntry::make('name')->label(__('admin.game.caravans.standings_columns.name')),
                    TextEntry::make('stars')->label(__('admin.game.caravans.standings_columns.stars')),
                    TextEntry::make('runs')->label(__('admin.game.caravans.standings_columns.runs')),
                    TextEntry::make('best_score')->label(__('admin.game.caravans.standings_columns.best_score')),
                    TextEntry::make('boss_won')
                        ->label(__('admin.game.caravans.standings_columns.boss_won'))
                        ->formatStateUsing(fn (mixed $state): string => __($state ? 'admin.common.yes' : 'admin.common.no')),
                ])
                ->columns(5),
        ]);
    }

    public static function table(Table $table): Table
    {
        return AdminTable::big($table)
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('group.title')->label(__('admin.game.caravans.fields.group'))->limit(32),
                Tables\Columns\TextColumn::make('organization.name')->label(__('admin.game.caravans.fields.organization'))->limit(32)->placeholder('—'),
                Tables\Columns\TextColumn::make('course')->label(__('admin.game.caravans.fields.course'))->state(fn (ClassCaravan $record): string => self::courseName($record)),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.game.caravans.fields.status'))
                    ->badge()
                    ->state(fn (ClassCaravan $record): string => $record->status->value)
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('game.caravans.status', $state))
                    ->color(fn (string $state): string => match ($state) {
                        ClassCaravanStatus::Active->value => 'success',
                        ClassCaravanStatus::Finished->value => 'gray',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('stars_total')
                    ->label(__('admin.game.caravans.fields.progress'))
                    ->state(fn (ClassCaravan $record): string => self::progress($record))
                    ->sortable(),
                Tables\Columns\TextColumn::make('starts_at')
                    ->label(__('admin.game.caravans.fields.window'))
                    ->state(fn (ClassCaravan $record): string => self::window($record))
                    ->sortable(),
                Tables\Columns\TextColumn::make('boss')
                    ->label(__('admin.game.caravans.fields.boss'))
                    ->badge()
                    ->state(fn (ClassCaravan $record): string => self::bossState($record))
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('game.caravans.boss', $state)),
                Tables\Columns\TextColumn::make('summary_posted_at')
                    ->label(__('admin.game.caravans.fields.summary_posted_at'))
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.game.caravans.fields.status'))
                    ->options(AdminLabels::options('game.caravans.status', array_map(fn (ClassCaravanStatus $s): string => $s->value, ClassCaravanStatus::cases()))),
                Tables\Filters\SelectFilter::make('organization_id')
                    ->label(__('admin.game.caravans.fields.organization'))
                    ->searchable()
                    ->options(fn (): array => self::organizationOptions(''))
                    ->getSearchResultsUsing(fn (string $search): array => self::organizationOptions(trim($search))),
            ])
            ->emptyStateHeading(__('admin.game.caravans.empty'))
            ->recordActions([
                Actions\ViewAction::make(),
                self::cancelAction(),
            ])
            ->defaultSort('id', 'desc');
    }

    /** "Отменить" with a confirmation: for the list and for the page of a caravan. */
    public static function cancelAction(): Actions\Action
    {
        return Actions\Action::make('cancel')
            ->label(__('admin.game.caravans.actions.cancel'))
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('admin.game.caravans.actions.cancel_confirm'))
            ->visible(fn (ClassCaravan $record): bool => $record->isActive())
            ->authorize(fn (ClassCaravan $record): bool => static::can('cancel', $record))
            ->action(fn (ClassCaravan $record) => self::cancel($record));
    }

    /** Cancels a caravan on its way and tells the chat of its group (no summary follows). */
    public static function cancel(ClassCaravan $caravan): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403);
        }

        try {
            $cancellation = app(ClassCaravanService::class)->cancel($caravan, $user);
        } catch (GameException) {
            Notification::make()->title(__('admin.game.caravans.notifications.cancel_failed'))->danger()->send();

            return;
        }

        $group = $caravan->group;
        if ($cancellation->canceledNow && $group instanceof TelegramGroup) {
            app(ClassCaravanMessenger::class)->announceCanceled($group);
        }

        $cancellation->canceledNow
            ? Notification::make()->title(__('admin.game.caravans.notifications.canceled'))->success()->send()
            : Notification::make()->title(__('admin.game.caravans.notifications.already_over'))->warning()->send();
    }

    /** `defeated` / `ready` (the target is reached) / `locked`. */
    public static function bossState(ClassCaravan $caravan): string
    {
        return match (true) {
            $caravan->boss_defeated_at !== null => 'defeated',
            $caravan->boss_unlocked_at !== null => 'ready',
            default => 'locked',
        };
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClassCaravans::route('/'),
            'view' => Pages\ViewClassCaravan::route('/{record}'),
        ];
    }

    private static function progress(ClassCaravan $caravan): string
    {
        return __('admin.game.caravans.progress_of', ['stars' => $caravan->stars_total, 'target' => $caravan->target_stars]);
    }

    private static function window(ClassCaravan $caravan): string
    {
        return $caravan->starts_at->format('d.m.Y H:i').' — '.$caravan->ends_at->format('d.m.Y H:i');
    }

    private static function courseName(ClassCaravan $caravan): string
    {
        $course = $caravan->course;

        return $course instanceof Course ? $course->slug : '#'.$caravan->course_id;
    }

    /**
     * Centers by name; the first fifty, or the fifty that match what is typed.
     *
     * @return array<int, string> organization id => name
     */
    private static function organizationOptions(string $search): array
    {
        /** @var array<int, string> $options */
        $options = Organization::query()
            ->when($search !== '', fn (Builder $q): Builder => AdminTable::whereContains($q, 'organizations.name', $search))
            ->orderBy('name')
            ->limit(50)
            ->pluck('name', 'id')
            ->all();

        return $options;
    }
}
