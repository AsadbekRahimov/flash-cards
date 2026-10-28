<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrganizationResource\RelationManagers;

use App\Domain\Organizations\Exceptions\OrganizationMembershipException;
use App\Domain\Organizations\Services\OrganizationMembershipService;
use App\Filament\Resources\Support\AdminLabels;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Members and seats of an organization (docs/13 §1.3, §6.1). Add and remove go through
 * {@see OrganizationMembershipService} (`joinByAdmin` / `leave(..., 'removed')`: seat rules, the waiting list,
 * entitlement cache, audit) — never through the stock create / delete actions. Active members are shown by default.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.organizations.members.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('admin.organizations.members.singular'))
            ->pluralModelLabel(__('admin.organizations.members.plural'))
            ->emptyStateHeading(__('admin.organizations.members.empty'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with('learner'))
            ->columns([
                Tables\Columns\TextColumn::make('learner.first_name')
                    ->label(__('admin.organizations.members.columns.learner'))
                    ->state(fn (OrganizationMember $record): string => $this->learnerLabel($record->learner))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'learner',
                        fn (Builder $learner) => $learner
                            ->where('first_name', 'like', '%'.$search.'%')
                            ->orWhere('last_name', 'like', '%'.$search.'%')
                            ->orWhere('username', 'like', '%'.ltrim($search, '@').'%'),
                    )),
                Tables\Columns\TextColumn::make('learner.telegram_user_id')->label(__('admin.organizations.members.columns.telegram_id'))->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('seat_state')
                    ->label(__('admin.organizations.members.columns.seat_state'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('organizations.members.seat_state', $state))
                    ->color(fn (string $state): string => match ($state) {
                        'seated' => 'success',
                        'waitlisted' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.organizations.members.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('organizations.members.status', $state))
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('joined_via')
                    ->label(__('admin.organizations.members.columns.joined_via'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('organizations.members.joined_via', $state))
                    ->color('gray'),
                Tables\Columns\TextColumn::make('joined_at')->label(__('admin.organizations.members.columns.joined_at'))->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('waitlisted_at')->label(__('admin.organizations.members.columns.waitlisted_at'))->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('left_at')->label(__('admin.organizations.members.columns.left_at'))->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.organizations.members.columns.status'))
                    ->options(AdminLabels::options('organizations.members.status', ['active', 'left', 'removed']))
                    ->default('active'),
                SelectFilter::make('seat_state')
                    ->label(__('admin.organizations.members.columns.seat_state'))
                    ->options(AdminLabels::options('organizations.members.seat_state', ['seated', 'waitlisted', 'none'])),
            ])
            ->headerActions([
                Action::make('addMember')
                    ->label(__('admin.organizations.members.actions.add'))
                    ->icon('heroicon-o-user-plus')
                    ->authorize(fn (): bool => Gate::allows('create', OrganizationMember::class))
                    ->schema([
                        Forms\Components\Select::make('learner_id')
                            ->label(__('admin.organizations.members.actions.add_field'))
                            ->required()
                            ->searchable()
                            ->helperText(__('admin.organizations.members.actions.add_help'))
                            ->getSearchResultsUsing(fn (string $search): array => $this->searchLearners($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => ($learner = Learner::query()->whereKey($value)->first()) === null ? null : $this->learnerLabel($learner)),
                    ])
                    ->action(function (array $data): void {
                        $learner = Learner::query()->whereKey($data['learner_id'] ?? 0)->first();
                        if ($learner === null) {
                            Notification::make()->title(__('admin.organizations.members.notifications.not_found'))->danger()->send();

                            return;
                        }

                        $service = app(OrganizationMembershipService::class);
                        $organization = $this->organization();

                        if ($service->activeMembership($learner, $organization) !== null) {
                            Notification::make()->title(__('admin.organizations.members.notifications.already_member'))->warning()->send();

                            return;
                        }

                        try {
                            $member = $service->joinByAdmin($learner, $organization, $this->actor(), request()->ip());
                        } catch (OrganizationMembershipException $e) {
                            Notification::make()->title(__('admin.organizations.members.notifications.not_added'))->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title($member->isSeated()
                                ? __('admin.organizations.members.notifications.added_seated')
                                : __('admin.organizations.members.notifications.added_waitlisted'))
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Action::make('removeMember')
                    ->label(__('admin.organizations.members.actions.remove'))
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->visible(fn (OrganizationMember $record): bool => $record->isActive())
                    ->authorize(fn (OrganizationMember $record): bool => Gate::allows('update', $record))
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.organizations.members.actions.remove_help'))
                    ->action(function (OrganizationMember $record): void {
                        $learner = Learner::withTrashed()->find($record->learner_id);
                        if ($learner === null) {
                            return;
                        }

                        try {
                            app(OrganizationMembershipService::class)->leave($learner, $this->organization(), 'removed', $this->actor(), request()->ip());
                        } catch (OrganizationMembershipException $e) {
                            Notification::make()->title(__('admin.organizations.members.notifications.already_removed'))->body($e->getMessage())->warning()->send();

                            return;
                        }

                        Notification::make()->title(__('admin.organizations.members.notifications.removed'))->success()->send();
                    }),
            ])
            ->defaultSort('joined_at', 'desc');
    }

    /** @return array<int, string> */
    private function searchLearners(string $search): array
    {
        $search = trim($search);
        $username = ltrim($search, '@');

        return Learner::query()
            ->where(function (Builder $query) use ($search, $username): void {
                $query->where('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%')
                    ->orWhere('username', 'like', '%'.$username.'%');

                if (ctype_digit($search)) {
                    $query->orWhere('telegram_user_id', (int) $search);
                }
            })
            ->orderBy('first_name')
            ->limit(30)
            ->get()
            ->mapWithKeys(fn (Learner $learner): array => [$learner->id => $this->learnerLabel($learner)])
            ->all();
    }

    private function learnerLabel(?Learner $learner): string
    {
        if ($learner === null) {
            return __('admin.organizations.members.deleted_learner');
        }

        $name = trim(($learner->first_name ?? '').' '.($learner->last_name ?? ''));

        return ($name === '' ? '#'.$learner->id : $name)
            .($learner->username !== null ? ' (@'.$learner->username.')' : '')
            .' · '.$learner->telegram_user_id;
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
