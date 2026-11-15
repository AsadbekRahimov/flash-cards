<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrganizationResource\RelationManagers;

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Exceptions\OrganizationStaffException;
use App\Domain\Organizations\Services\OrganizationStaffService;
use App\Filament\Resources\Support\AdminErrors;
use App\Filament\Resources\Support\AdminTable;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Staff of an organization (docs/13 §1.3, §6.3): the way a center gets its FIRST owner — the `/org` «Teachers» screen
 * already needs one. The invitation goes through {@see OrganizationStaffService::invite} (account, staff row, audit,
 * letter with a password link); nothing of that is repeated here. Active admins only, like the rest of the resource.
 */
class StaffRelationManager extends RelationManager
{
    protected static string $relationship = 'staff';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.organizations.staff.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::isActiveAdmin(auth()->user());
    }

    public function table(Table $table): Table
    {
        return AdminTable::bounded($table)
            ->modelLabel(__('admin.organizations.staff.singular'))
            ->pluralModelLabel(__('admin.organizations.staff.plural'))
            ->emptyStateHeading(__('admin.organizations.staff.empty'))
            ->emptyStateDescription(__('admin.organizations.staff.empty_help'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user:id,name,email,last_login_at'))
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label(__('admin.organizations.staff.columns.name')),
                Tables\Columns\TextColumn::make('user.email')->label(__('admin.organizations.staff.columns.email')),
                Tables\Columns\TextColumn::make('role')
                    ->label(__('admin.organizations.staff.columns.role'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('admin.organizations.staff.role.'.$state))
                    ->color(fn (string $state): string => $state === OrganizationRole::Owner->value ? 'primary' : 'gray')
                    ->sortable(),
                // The user rides along with the page query (`with`), so the closure reads no relation lazily.
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.organizations.staff.columns.status'))
                    ->badge()
                    ->state(fn (OrganizationUser $record): string => $record->user?->last_login_at === null ? 'invited' : 'active')
                    ->formatStateUsing(fn (string $state): string => __('admin.organizations.staff.status.'.$state))
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('created_at')->label(__('admin.organizations.staff.columns.created_at'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Action::make('inviteStaff')
                    ->label(__('admin.organizations.staff.actions.invite'))
                    ->icon('heroicon-o-envelope')
                    ->authorize(fn (): bool => self::isActiveAdmin(auth()->user()))
                    ->modalDescription(__('admin.organizations.staff.actions.invite_help'))
                    ->schema([
                        Forms\Components\TextInput::make('email')->label(__('admin.organizations.staff.fields.email'))->email()->required()->maxLength(255),
                        Forms\Components\TextInput::make('name')->label(__('admin.organizations.staff.fields.name'))->maxLength(255),
                        Forms\Components\Select::make('role')
                            ->label(__('admin.organizations.staff.columns.role'))
                            ->options([
                                OrganizationRole::Owner->value => __('admin.organizations.staff.role.owner'),
                                OrganizationRole::Teacher->value => __('admin.organizations.staff.role.teacher'),
                            ])
                            ->default(OrganizationRole::Owner->value)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        try {
                            app(OrganizationStaffService::class)->invite(
                                $this->organization(),
                                (string) $data['email'],
                                isset($data['name']) && $data['name'] !== '' ? (string) $data['name'] : null,
                                OrganizationRole::from((string) $data['role']),
                                $this->actor(),
                                request()->ip(),
                            );
                        } catch (OrganizationStaffException $e) {
                            Notification::make()->title(__('admin.organizations.staff.notifications.not_invited'))->body(AdminErrors::for('organizations.staff', $e))->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('admin.organizations.staff.notifications.invited'))->success()->send();
                    }),
            ])
            ->defaultSort('id');
    }

    private static function isActiveAdmin(mixed $user): bool
    {
        return $user instanceof User && $user->is_active && $user->hasRole('admin');
    }

    private function organization(): Organization
    {
        /** @var Organization $organization */
        $organization = $this->getOwnerRecord();

        return $organization;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
