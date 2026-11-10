<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources;

use App\Domain\Organizations\Enums\MemberStatus;
use App\Domain\Organizations\Enums\SeatState;
use App\Domain\Organizations\Exceptions\OrganizationMembershipException;
use App\Domain\Organizations\Services\OrganizationMembershipService;
use App\Filament\Org\Resources\MemberResource\Pages;
use App\Filament\Resources\Support\AdminTable;
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
 * Members (seats) of the organization (docs/13 §6.3): owner — all rows, "invite" by Telegram username / id (an offer the learner
 * accepts in the bot — audit 2026-11-10 M2; one neutral answer, found or not), "revoke" of an unanswered offer and "remove";
 * teacher — the active members of their groups, read-only. No Telegram ids, no settings, no personal sessions are shown
 * (docs/12 §4.4 privacy). Writes go through OrganizationMembershipService (row lock, FIFO reseat, audit, events).
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

    /**
     * The row policy of a teacher needs the learner's active group memberships (`OrgTenantScope::canSeeMember`):
     * loaded with the list, not queried per row (docs/20 §1.3). An owner sees every row, so only the learner.
     *
     * @return Builder<OrganizationMember>
     */
    public static function getEloquentQuery(): Builder
    {
        return static::scope()->members(static::user(), static::tenant())
            ->with(static::isOwner() ? ['learner'] : ['learner.activeGroupMemberships']);
    }

    public static function table(Table $table): Table
    {
        // Page sizes 10/25/50/100 and nothing else (docs/20 §1.6): a forged $tableRecordsPerPage gets the default, not every row.
        return AdminTable::bounded($table)
            ->columns([
                Tables\Columns\TextColumn::make('learner_name')
                    ->label(__('org.members.columns.learner'))
                    ->state(fn (OrganizationMember $record): string => self::learnerName($record->learner))
                    // Case-insensitive, in the forms the trigram indexes of `learners` serve (docs/03 §2.17, docs/20 F2); `%`, `_`
                    // and `!` of the typed text are literal (AdminTable::whereLearnerContains).
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'learner',
                        fn (Builder $learner): Builder => AdminTable::whereLearnerContains($learner, $search),
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
                    ->color(fn (string $state): string => match ($state) {
                        MemberStatus::Active->value => 'success',
                        MemberStatus::Invited->value => 'warning',
                        default => 'gray',
                    }),
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
                        MemberStatus::Invited->value => __('org.members.status.invited'),
                        MemberStatus::Declined->value => __('org.members.status.declined'),
                        MemberStatus::Left->value => __('org.members.status.left'),
                        MemberStatus::Removed->value => __('org.members.status.removed'),
                    ])
                    // Offers waiting for the learner's answer are shown with the members by default, so the owner sees «Ожидает согласия».
                    ->multiple()
                    ->default([MemberStatus::Active->value, MemberStatus::Invited->value]),
                Tables\Filters\SelectFilter::make('seat_state')
                    ->label(__('org.members.columns.seat'))
                    ->options([
                        SeatState::Seated->value => __('org.members.seat.seated'),
                        SeatState::Waitlisted->value => __('org.members.seat.waitlisted'),
                    ]),
            ])
            ->headerActions([
                // An OFFER the learner answers in the bot (audit 2026-11-10 M2). ONE notification whatever happened — found or not,
                // reachable or not, already a member or not: the answer must not say who uses LexiFlow.
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
                        try {
                            app(OrganizationMembershipService::class)->inviteByStaff(static::tenant(), (string) $data['telegram'], static::user(), static::ip());
                        } catch (OrganizationMembershipException $e) {
                            Notification::make()->title(__('org.members.errors.'.$e->errorCode))->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('org.members.notifications.invited'))->success()->send();
                    }),
            ])
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\Action::make('revoke')
                    ->label(__('org.members.actions.revoke'))
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(__('org.members.actions.revoke_confirm'))
                    ->visible(fn (OrganizationMember $record): bool => $record->isInvited())
                    ->authorize(fn (OrganizationMember $record): bool => static::isOwner() && $record->isInvited())
                    ->action(function (OrganizationMember $record): void {
                        try {
                            app(OrganizationMembershipService::class)->revokeInvite(static::tenant(), $record, static::user(), static::ip());
                        } catch (OrganizationMembershipException $e) {
                            Notification::make()->title(__('org.members.errors.'.$e->errorCode))->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('org.members.notifications.revoked'))->success()->send();
                    }),
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
}
