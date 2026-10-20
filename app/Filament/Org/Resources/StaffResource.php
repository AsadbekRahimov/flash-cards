<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources;

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Exceptions\OrganizationStaffException;
use App\Domain\Organizations\Services\OrganizationStaffService;
use App\Filament\Org\Resources\StaffResource\Pages;
use App\Models\OrganizationUser;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Staff of the organization — "Teachers" (docs/13 §6.3): owner only. Invite by email (letter with a
 * password link), assign groups (`teacher_groups`), change role, resend the invitation, remove.
 * The Telegram account is linked by the teacher themselves in the profile (`/org/profile`), never
 * by the owner: a user row may be staff of several centers.
 */
class StaffResource extends OrgResource
{
    protected static ?string $model = OrganizationUser::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?int $navigationSort = 40;

    public static function getNavigationLabel(): string
    {
        return __('org.nav.teachers');
    }

    public static function getModelLabel(): string
    {
        return __('org.teachers.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('org.teachers.plural');
    }

    /** @return Builder<OrganizationUser> */
    public static function getEloquentQuery(): Builder
    {
        return static::scope()->staff(static::user(), static::tenant())->with('user');
    }

    public static function table(Table $table): Table
    {
        $staff = app(OrganizationStaffService::class);

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label(__('org.teachers.columns.name'))->searchable(),
                Tables\Columns\TextColumn::make('user.email')->label(__('org.teachers.columns.email'))->searchable(),
                Tables\Columns\TextColumn::make('role')
                    ->label(__('org.teachers.columns.role'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('org.teachers.role.'.$state))
                    ->color(fn (string $state): string => $state === OrganizationRole::Owner->value ? 'primary' : 'gray'),
                Tables\Columns\IconColumn::make('telegram_linked')
                    ->label(__('org.teachers.columns.telegram'))
                    ->boolean()
                    ->state(fn (OrganizationUser $record): bool => $record->user?->telegram_user_id !== null),
                Tables\Columns\TextColumn::make('groups_count')
                    ->label(__('org.teachers.columns.groups'))
                    ->state(fn (OrganizationUser $record): int => count($staff->groupIdsOf(static::tenant(), $record))),
                Tables\Columns\TextColumn::make('user.last_login_at')
                    ->label(__('org.teachers.columns.last_login'))
                    ->since()
                    ->placeholder(__('org.teachers.never_logged_in')),
            ])
            ->headerActions([
                Actions\Action::make('invite')
                    ->label(__('org.teachers.actions.invite'))
                    ->icon('heroicon-o-envelope')
                    ->authorize(fn (): bool => static::canCreate())
                    ->schema([
                        Forms\Components\TextInput::make('email')->label(__('org.teachers.fields.email'))->email()->required()->maxLength(255),
                        Forms\Components\TextInput::make('name')->label(__('org.teachers.fields.name'))->maxLength(255),
                        Forms\Components\Select::make('role')
                            ->label(__('org.teachers.columns.role'))
                            ->options(self::roleOptions())
                            ->default(OrganizationRole::Teacher->value)
                            ->required(),
                    ])
                    ->action(function (array $data) use ($staff): void {
                        try {
                            $staff->invite(
                                static::tenant(),
                                (string) $data['email'],
                                isset($data['name']) ? (string) $data['name'] : null,
                                OrganizationRole::from((string) $data['role']),
                                static::user(),
                                static::ip(),
                            );
                        } catch (OrganizationStaffException $e) {
                            Notification::make()->title(__('org.teachers.errors.'.$e->errorCode))->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('org.teachers.notifications.invited'))->success()->send();
                    }),
            ])
            ->recordActions([
                Actions\Action::make('groups')
                    ->label(__('org.teachers.actions.groups'))
                    ->icon('heroicon-o-rectangle-group')
                    ->authorize(fn (OrganizationUser $record): bool => static::canEdit($record))
                    ->fillForm(fn (OrganizationUser $record): array => ['groups' => $staff->groupIdsOf(static::tenant(), $record)])
                    ->schema([
                        Forms\Components\CheckboxList::make('groups')
                            ->label(__('org.nav.groups'))
                            ->options(fn (): array => static::scope()->groups(static::user(), static::tenant())->orderBy('title')->pluck('title', 'id')->all()),
                    ])
                    ->action(function (OrganizationUser $record, array $data) use ($staff): void {
                        /** @var list<int> $groupIds */
                        $groupIds = array_map(intval(...), (array) ($data['groups'] ?? []));

                        try {
                            $staff->syncGroups(static::tenant(), $record, $groupIds, static::user(), static::ip());
                        } catch (OrganizationStaffException $e) {
                            Notification::make()->title(__('org.teachers.errors.'.$e->errorCode))->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('org.teachers.notifications.groups_saved'))->success()->send();
                    }),
                Actions\Action::make('role')
                    ->label(__('org.teachers.actions.role'))
                    ->icon('heroicon-o-identification')
                    ->authorize(fn (OrganizationUser $record): bool => static::canEdit($record))
                    ->fillForm(fn (OrganizationUser $record): array => ['role' => $record->role])
                    ->schema([
                        Forms\Components\Select::make('role')->label(__('org.teachers.columns.role'))->options(self::roleOptions())->required(),
                    ])
                    ->action(function (OrganizationUser $record, array $data) use ($staff): void {
                        try {
                            $staff->changeRole(static::tenant(), $record, OrganizationRole::from((string) $data['role']), static::user(), static::ip());
                        } catch (OrganizationStaffException $e) {
                            Notification::make()->title(__('org.teachers.errors.'.$e->errorCode))->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('org.teachers.notifications.role_saved'))->success()->send();
                    }),
                Actions\Action::make('resend')
                    ->label(__('org.teachers.actions.resend'))
                    ->icon('heroicon-o-paper-airplane')
                    ->requiresConfirmation()
                    ->visible(fn (OrganizationUser $record): bool => $record->user?->last_login_at === null)
                    ->authorize(fn (OrganizationUser $record): bool => static::canEdit($record))
                    ->action(function (OrganizationUser $record) use ($staff): void {
                        $staff->resendInvitation(static::tenant(), $record, static::user(), static::ip());

                        Notification::make()->title(__('org.teachers.notifications.resent'))->success()->send();
                    }),
                Actions\Action::make('remove')
                    ->label(__('org.teachers.actions.remove'))
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->hidden(fn (OrganizationUser $record): bool => $record->user_id === static::user()->id)
                    ->authorize(fn (OrganizationUser $record): bool => static::canDelete($record))
                    ->action(function (OrganizationUser $record) use ($staff): void {
                        try {
                            $staff->remove(static::tenant(), $record, static::user(), static::ip());
                        } catch (OrganizationStaffException $e) {
                            Notification::make()->title(__('org.teachers.errors.'.$e->errorCode))->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('org.teachers.notifications.removed'))->success()->send();
                    }),
            ])
            ->defaultSort('created_at');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaff::route('/'),
        ];
    }

    /** @return array<string, string> */
    private static function roleOptions(): array
    {
        return [
            OrganizationRole::Teacher->value => __('org.teachers.role.teacher'),
            OrganizationRole::Owner->value => __('org.teachers.role.owner'),
        ];
    }
}
