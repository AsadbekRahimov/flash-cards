<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources;

use App\Domain\Organizations\Enums\MemberStatus;
use App\Domain\Organizations\Enums\SeatState;
use App\Domain\Organizations\Exceptions\OrganizationMembershipException;
use App\Domain\Organizations\Services\OrganizationMembershipService;
use App\Filament\Org\Resources\MemberResource\Pages;
use App\Models\Learner;
use App\Models\OrganizationMember;
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
 * Members (seats) of the organization (docs/13 §6.3): owner — all rows, "add" by Telegram
 * username / id and "remove"; teacher — the active members of their groups, read-only. No
 * Telegram ids, no settings, no personal sessions are shown (docs/12 §4.4 privacy).
 * Writes go through OrganizationMembershipService (row lock, FIFO reseat, audit, events).
 */
class MemberResource extends OrgResource
{
    protected static ?string $model = OrganizationMember::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('org.nav.members');
    }

    public static function getModelLabel(): string
    {
        return __('org.members.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('org.members.plural');
    }

    /** @return Builder<OrganizationMember> */
    public static function getEloquentQuery(): Builder
    {
        return static::scope()->members(static::user(), static::tenant())->with('learner');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('learner_name')
                    ->label(__('org.members.columns.learner'))
                    ->state(fn (OrganizationMember $record): string => self::learnerName($record->learner))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'learner',
                        fn (Builder $q): Builder => $q->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%"),
                    )),
                Tables\Columns\TextColumn::make('learner.username')
                    ->label(__('org.members.columns.username'))
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : '@'.$state),
                Tables\Columns\TextColumn::make('seat_state')
                    ->label(__('org.members.columns.seat'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('org.members.seat.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        SeatState::Seated->value => 'success',
                        SeatState::Waitlisted->value => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('org.members.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('org.members.status.'.$state))
                    ->color(fn (string $state): string => $state === MemberStatus::Active->value ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('joined_via')
                    ->label(__('org.members.columns.joined_via'))
                    ->formatStateUsing(fn (string $state): string => __('org.members.via.'.$state))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('joined_at')
                    ->label(__('org.members.columns.joined_at'))
                    ->dateTime('d.m.Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('learner.last_seen_at')
                    ->label(__('org.members.columns.last_seen'))
                    ->since()
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('org.members.columns.status'))
                    ->options([
                        MemberStatus::Active->value => __('org.members.status.active'),
                        MemberStatus::Left->value => __('org.members.status.left'),
                        MemberStatus::Removed->value => __('org.members.status.removed'),
                    ])
                    ->default(MemberStatus::Active->value),
                Tables\Filters\SelectFilter::make('seat_state')
                    ->label(__('org.members.columns.seat'))
                    ->options([
                        SeatState::Seated->value => __('org.members.seat.seated'),
                        SeatState::Waitlisted->value => __('org.members.seat.waitlisted'),
                    ]),
            ])
            ->headerActions([
                Actions\Action::make('add')
                    ->label(__('org.members.actions.add'))
                    ->icon('heroicon-o-user-plus')
                    ->authorize(fn (): bool => static::canCreate())
                    ->schema([
                        Forms\Components\TextInput::make('telegram')
                            ->label(__('org.members.fields.telegram'))
                            ->helperText(__('org.members.fields.telegram_help'))
                            ->required()
                            ->maxLength(64),
                    ])
                    ->action(function (array $data): void {
                        $learner = self::findLearner((string) $data['telegram']);

                        if ($learner === null) {
                            Notification::make()->title(__('org.members.errors.learner_not_found'))->danger()->send();

                            return;
                        }

                        try {
                            $member = app(OrganizationMembershipService::class)->joinByAdmin($learner, static::tenant(), static::user(), static::ip());
                        } catch (OrganizationMembershipException $e) {
                            Notification::make()->title(__('org.members.errors.'.$e->errorCode))->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('org.members.notifications.added', ['seat' => __('org.members.seat.'.$member->seat_state)]))
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\Action::make('remove')
                    ->label(__('org.members.actions.remove'))
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('org.members.actions.remove_confirm'))
                    ->authorize(fn (OrganizationMember $record): bool => static::canDelete($record))
                    ->action(function (OrganizationMember $record): void {
                        /** @var Learner $learner */
                        $learner = $record->learner()->firstOrFail();

                        try {
                            app(OrganizationMembershipService::class)->leave($learner, static::tenant(), MemberStatus::Removed->value, static::user(), static::ip());
                        } catch (OrganizationMembershipException $e) {
                            Notification::make()->title(__('org.members.errors.'.$e->errorCode))->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('org.members.notifications.removed'))->success()->send();
                    }),
            ])
            ->defaultSort('joined_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Infolists\Components\TextEntry::make('learner_name')
                ->label(__('org.members.columns.learner'))
                ->state(fn (OrganizationMember $record): string => self::learnerName($record->learner)),
            Infolists\Components\TextEntry::make('learner.username')
                ->label(__('org.members.columns.username'))
                ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : '@'.$state),
            Infolists\Components\TextEntry::make('seat_state')
                ->label(__('org.members.columns.seat'))
                ->badge()
                ->formatStateUsing(fn (string $state): string => __('org.members.seat.'.$state)),
            Infolists\Components\TextEntry::make('status')
                ->label(__('org.members.columns.status'))
                ->badge()
                ->formatStateUsing(fn (string $state): string => __('org.members.status.'.$state)),
            Infolists\Components\TextEntry::make('joined_via')
                ->label(__('org.members.columns.joined_via'))
                ->formatStateUsing(fn (string $state): string => __('org.members.via.'.$state)),
            Infolists\Components\TextEntry::make('joined_at')->label(__('org.members.columns.joined_at'))->dateTime('d.m.Y H:i'),
            Infolists\Components\TextEntry::make('seated_at')->label(__('org.members.columns.seated_at'))->dateTime('d.m.Y H:i')->placeholder('—'),
            Infolists\Components\TextEntry::make('learner.streak_current')->label(__('org.members.columns.streak')),
            Infolists\Components\TextEntry::make('learner.last_seen_at')->label(__('org.members.columns.last_seen'))->since()->placeholder('—'),
        ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMembers::route('/'),
            'view' => Pages\ViewMember::route('/{record}'),
        ];
    }

    public static function learnerName(?Learner $learner): string
    {
        if ($learner === null) {
            return '—';
        }

        $name = trim(($learner->first_name ?? '').' '.($learner->last_name ?? ''));

        return $name !== '' ? $name : ($learner->username !== null ? '@'.$learner->username : '#'.$learner->id);
    }

    /** `@username` / `username` (case-insensitive) or a numeric Telegram user id — exact match only, no browsing of the platform's learners. */
    private static function findLearner(string $input): ?Learner
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        if (ctype_digit($input)) {
            /** @var Learner|null $learner */
            $learner = Learner::query()->where('telegram_user_id', (int) $input)->first();

            return $learner;
        }

        $username = ltrim($input, '@');

        if (preg_match('/^[A-Za-z0-9_]{3,64}$/', $username) !== 1) {
            return null;
        }

        /** @var Learner|null $learner */
        $learner = Learner::query()->whereRaw('LOWER(username) = ?', [strtolower($username)])->first();

        return $learner;
    }
}
