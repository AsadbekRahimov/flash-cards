<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Cup\Enums\CupStatus;
use App\Domain\Cup\Services\CupPlatformAdmin;
use App\Domain\Cup\Support\CupAccess;
use App\Domain\Cup\Support\CupClock;
use App\Filament\Resources\CupResource\Pages;
use App\Filament\Resources\CupResource\RelationManagers;
use App\Filament\Resources\Support\AdminLabels;
use App\Filament\Resources\Support\AdminTable;
use App\Models\Cup;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The friends' cups of the Telegram groups (docs/33 §8, K9): read-only, an active platform admin only (`CupPolicy`). The list is one
 * query of the page - the group and the course by one `with` each, the participants and those who played by one grouped statement over
 * the ids of the page ({@see withParticipantCounts()}), the day of the cup and the pause computed from the loaded row, never queried. The page
 * of a cup shows its participants (the table, "Сбросить имя") and the attempts of one day (simple pagination, narrowed by the day).
 *
 * The only writes: "Отменить кубок" (reason required, confirmation; `cancel_reason = platform_admin`, `audit_logs cup.canceled`),
 * "Сбросить имя", and the flags of the group ("Запретить кубки", "Кубок: пилот" - also on the list of Telegram groups), all through
 * {@see CupPlatformAdmin}, which checks the role once more.
 */
class CupResource extends Resource
{
    protected static ?string $model = Cup::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-trophy';

    protected static ?int $navigationSort = 25;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.administration');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.cups.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.cups.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.cups.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return Builder<Cup> */
    public static function getEloquentQuery(): Builder
    {
        // No `seed_secret`, `word_plan`, `meta` or the state of the card: the panel shows none of them.
        return Cup::query()
            ->select([
                'cups.id', 'cups.telegram_group_id', 'cups.status', 'cups.cancel_reason', 'cups.course_id', 'cups.level', 'cups.timezone',
                'cups.days', 'cups.counted_days', 'cups.round_tasks', 'cups.starts_at', 'cups.ends_at', 'cups.summaries_enabled',
                'cups.summary_minute', 'cups.locale', 'cups.confirmed_at', 'cups.finished_at', 'cups.canceled_at', 'cups.created_at',
            ])
            ->with(['telegramGroup:id,title,bot_status,meta', 'course:id,slug'])
            ->afterQuery(fn (EloquentCollection $cups): EloquentCollection => self::withParticipantCounts($cups));
    }

    /**
     * The participants and those who played (`days_played > 0`) of the cups of the page: ONE grouped statement over their ids (the unique
     * `(cup_id, learner_id)`) after the page is read. Not `withCount`: its correlated subqueries are in the select list, and PostgreSQL
     * evaluates them for every row an OFFSET skips too - the last page of 5 000 cups read 140 000 buffers of participants (docs/20 §1.3).
     *
     * @param  EloquentCollection<int, Cup>  $cups
     * @return EloquentCollection<int, Cup>
     */
    public static function withParticipantCounts(EloquentCollection $cups): EloquentCollection
    {
        if ($cups->isEmpty()) {
            return $cups;
        }

        $counts = DB::table('cup_participants')
            ->whereIn('cup_id', $cups->modelKeys())
            ->groupBy('cup_id')
            ->selectRaw('cup_id, count(*) as participants, coalesce(sum(case when days_played > 0 then 1 else 0 end), 0) as played')
            ->get()
            ->keyBy('cup_id');
        foreach ($cups as $cup) {
            $row = $counts->get($cup->id);
            $cup->setAttribute('participants_count', (int) ($row->participants ?? 0));
            $cup->setAttribute('played_count', (int) ($row->played ?? 0));
            $cup->syncOriginalAttributes(['participants_count', 'played_count']);
        }

        return $cups;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('telegramGroup.title')->label(__('admin.cups.fields.group')),
            TextEntry::make('status')
                ->label(__('admin.cups.fields.status'))
                ->badge()
                ->state(fn (Cup $record): string => $record->status->value)
                ->formatStateUsing(fn (string $state): string => AdminLabels::value('cups.status', $state)),
            TextEntry::make('cancel_reason')
                ->label(__('admin.cups.fields.cancel_reason'))
                ->state(fn (Cup $record): ?string => $record->cancel_reason?->value)
                ->formatStateUsing(fn (string $state): string => AdminLabels::value('cups.cancel_reason', $state))
                ->placeholder('—'),
            TextEntry::make('paused')
                ->label(__('admin.cups.fields.paused'))
                ->state(fn (Cup $record): string => self::pause($record))
                ->formatStateUsing(fn (string $state): string => AdminLabels::value('cups.pause', $state)),
            TextEntry::make('level')->label(__('admin.cups.fields.level')),
            TextEntry::make('course')->label(__('admin.cups.fields.course'))->state(fn (Cup $record): string => self::courseName($record)),
            TextEntry::make('day')->label(__('admin.cups.fields.day'))->state(fn (Cup $record): string => self::day($record)),
            TextEntry::make('participants_count')->label(__('admin.cups.fields.participants')),
            TextEntry::make('played_count')->label(__('admin.cups.fields.played')),
            TextEntry::make('starts_at')->label(__('admin.cups.fields.starts_at'))->dateTime()->placeholder('—'),
            TextEntry::make('ends_at')->label(__('admin.cups.fields.ends_at'))->dateTime()->placeholder('—'),
            TextEntry::make('summaries')
                ->label(__('admin.cups.fields.summaries'))
                ->state(fn (Cup $record): string => $record->summaries_enabled
                    ? __('admin.cups.summaries_at', ['time' => sprintf('%02d:%02d', intdiv($record->summary_minute, 60), $record->summary_minute % 60)])
                    : __('admin.cups.summaries_off')),
            TextEntry::make('locale')->label(__('admin.cups.fields.locale')),
            TextEntry::make('created_at')->label(__('admin.cups.fields.created_at'))->dateTime(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return AdminTable::bounded($table)
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('telegramGroup.title')
                    ->label(__('admin.cups.fields.group'))
                    ->limit(32)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereIn(
                        'cups.telegram_group_id',
                        AdminTable::whereContains(TelegramGroup::query(), 'telegram_groups.title', $search)->select('telegram_groups.id'),
                    )),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.cups.fields.status'))
                    ->badge()
                    ->state(fn (Cup $record): string => $record->status->value)
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('cups.status', $state))
                    ->color(fn (string $state): string => match ($state) {
                        CupStatus::Running->value => 'success',
                        CupStatus::Scheduled->value, CupStatus::Draft->value => 'warning',
                        CupStatus::Finished->value => 'gray',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('paused')
                    ->label(__('admin.cups.fields.paused'))
                    ->badge()
                    ->state(fn (Cup $record): string => self::pause($record))
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('cups.pause', $state))
                    ->color(fn (string $state): string => $state === 'none' ? 'gray' : 'danger'),
                Tables\Columns\TextColumn::make('level')->label(__('admin.cups.fields.level')),
                Tables\Columns\TextColumn::make('course')->label(__('admin.cups.fields.course'))->state(fn (Cup $record): string => self::courseName($record)),
                Tables\Columns\TextColumn::make('day')->label(__('admin.cups.fields.day'))->state(fn (Cup $record): string => self::day($record)),
                Tables\Columns\TextColumn::make('participants_count')->label(__('admin.cups.fields.participants')),
                Tables\Columns\TextColumn::make('played_count')->label(__('admin.cups.fields.played')),
                Tables\Columns\TextColumn::make('starts_at')->label(__('admin.cups.fields.starts_at'))->dateTime()->placeholder('—'),
                Tables\Columns\TextColumn::make('ends_at')->label(__('admin.cups.fields.ends_at'))->dateTime()->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.cups.fields.status'))
                    ->options(AdminLabels::options('cups.status', array_map(fn (CupStatus $s): string => $s->value, CupStatus::cases()))),
                Tables\Filters\Filter::make('paused')
                    ->label(__('admin.cups.filters.paused'))
                    ->query(fn (Builder $query): Builder => $query->whereIn('cups.telegram_group_id', TelegramGroup::query()
                        ->select('telegram_groups.id')
                        ->where(fn (Builder $g): Builder => $g->where('telegram_groups.meta->cups_blocked', true)->orWhereIn('telegram_groups.bot_status', ['left', 'kicked'])))),
                Tables\Filters\Filter::make('pilot')
                    ->label(__('admin.cups.filters.pilot'))
                    ->query(fn (Builder $query): Builder => $query->whereIn('cups.telegram_group_id', TelegramGroup::query()
                        ->select('telegram_groups.id')
                        ->where('telegram_groups.meta->cup_pilot', true))),
            ])
            ->emptyStateHeading(__('admin.cups.empty'))
            ->recordActions([
                Actions\ViewAction::make(),
                self::cancelAction(),
            ])
            ->defaultSort('id', 'desc');
    }

    /** "Отменить кубок": the reason is required, the admin confirms; for the list and for the page of a cup. */
    public static function cancelAction(): Actions\Action
    {
        return Actions\Action::make('cancel')
            ->label(__('admin.cups.actions.cancel'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('admin.cups.actions.cancel_heading'))
            ->modalDescription(__('admin.cups.actions.cancel_confirm'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('admin.cups.actions.reason'))
                    ->rows(3)
                    ->required()
                    ->maxLength(CupPlatformAdmin::REASON_MAX),
            ])
            ->visible(fn (Cup $record): bool => $record->status->isOpen())
            ->authorize(fn (Cup $record): bool => static::can('cancel', $record))
            ->action(fn (Cup $record, array $data) => self::cancel($record, (string) ($data['reason'] ?? '')));
    }

    /** "Запретить кубки в группе" / "Разрешить кубки": the flag `meta.cups_blocked` of the group - an open cup is paused. */
    public static function blockAction(?\Closure $group = null): Actions\Action
    {
        $group ??= static fn (TelegramGroup $record): TelegramGroup => $record;

        return Actions\Action::make('cups_block')
            ->label(fn (mixed $record): string => __(self::flag($group($record), 'cups_blocked') ? 'admin.cups.actions.unblock' : 'admin.cups.actions.block'))
            ->icon('heroicon-o-no-symbol')
            ->color(fn (mixed $record): string => self::flag($group($record), 'cups_blocked') ? 'gray' : 'danger')
            ->requiresConfirmation()
            ->modalDescription(fn (mixed $record): string => __(self::flag($group($record), 'cups_blocked') ? 'admin.cups.actions.unblock_confirm' : 'admin.cups.actions.block_confirm'))
            ->visible(fn (): bool => static::can('manage'))
            ->authorize(fn (): bool => static::can('manage'))
            ->action(function (mixed $record) use ($group): void {
                $target = $group($record);
                self::run(fn (CupPlatformAdmin $admin, User $user): bool => $admin->setCupsBlocked($target, ! self::flag($target, 'cups_blocked'), $user, request()->ip()));
            });
    }

    /** "Кубок: пилот" on / off: the flag `meta.cup_pilot` of the group, read with `CUP_ACCESS=pilot`. */
    public static function pilotAction(?\Closure $group = null): Actions\Action
    {
        $group ??= static fn (TelegramGroup $record): TelegramGroup => $record;

        return Actions\Action::make('cup_pilot')
            ->label(fn (mixed $record): string => __(self::flag($group($record), 'cup_pilot') ? 'admin.cups.actions.pilot_off' : 'admin.cups.actions.pilot_on'))
            ->icon('heroicon-o-beaker')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('admin.cups.actions.pilot_confirm'))
            ->visible(fn (): bool => static::can('manage'))
            ->authorize(fn (): bool => static::can('manage'))
            ->action(function (mixed $record) use ($group): void {
                $target = $group($record);
                self::run(fn (CupPlatformAdmin $admin, User $user): bool => $admin->setPilot($target, ! self::flag($target, 'cup_pilot'), $user, request()->ip()));
            });
    }

    public static function cancel(Cup $cup, string $reason): void
    {
        self::run(fn (CupPlatformAdmin $admin, User $user): bool => $admin->cancel($cup, $user, $reason, request()->ip()), 'admin.cups.notifications.canceled', 'admin.cups.notifications.already_over');
    }

    /**
     * Runs an action of {@see CupPlatformAdmin} for the signed-in admin and tells the result.
     *
     * @param  \Closure(CupPlatformAdmin, User): bool  $action
     */
    public static function run(\Closure $action, string $done = 'admin.cups.notifications.saved', string $unchanged = 'admin.cups.notifications.unchanged'): void
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            abort(403);
        }

        try {
            $changed = $action(app(CupPlatformAdmin::class), $user);
        } catch (AuthorizationException) {
            abort(403);
        }

        $changed
            ? Notification::make()->title(__($done))->success()->send()
            : Notification::make()->title(__($unchanged))->warning()->send();
    }

    /** `none` / `blocked` / `bot_removed`, from the loaded group. */
    public static function pause(Cup $cup): string
    {
        return CupAccess::pauseReason($cup->telegramGroup)->value ?? 'none';
    }

    /** "День 3 из 7" while it runs; the status says the rest. No query: the borders are local midnights of the cup. */
    public static function day(Cup $cup): string
    {
        if ($cup->status !== CupStatus::Running || $cup->starts_at === null) {
            return '—';
        }
        $day = CupClock::dayNumber($cup->starts_at, $cup->timezone, CarbonImmutable::now());

        return __('admin.cups.day_of', ['day' => max(1, min($cup->days, $day)), 'days' => $cup->days]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCups::route('/'),
            'view' => Pages\ViewCup::route('/{record}'),
        ];
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [
            RelationManagers\ParticipantsRelationManager::class,
            RelationManagers\AttemptsRelationManager::class,
        ];
    }

    private static function flag(TelegramGroup $group, string $key): bool
    {
        return ($group->meta[$key] ?? false) === true;
    }

    /** The course is eager-loaded with the page (`course:id,slug`); `cups.course_id` is RESTRICT, so it is always there. */
    private static function courseName(Cup $cup): string
    {
        return $cup->course->slug;
    }
}
