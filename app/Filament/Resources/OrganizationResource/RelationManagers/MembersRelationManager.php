<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrganizationResource\RelationManagers;

use App\Domain\Organizations\Exceptions\OrganizationMembershipException;
use App\Domain\Organizations\Services\OrganizationMembershipService;
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
use Illuminate\Support\Facades\Gate;

/**
 * Members and seats of an organization (docs/13 §1.3, §6.1). Add and remove go through
 * {@see OrganizationMembershipService} (`joinByAdmin` / `leave(..., 'removed')`: seat rules, the waiting list,
 * entitlement cache, audit) — never through the stock create / delete actions. Active members are shown by default.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Members';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('learner'))
            ->columns([
                Tables\Columns\TextColumn::make('learner.first_name')
                    ->label('Learner')
                    ->state(fn (OrganizationMember $record): string => $this->learnerLabel($record->learner))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'learner',
                        fn (Builder $learner) => $learner
                            ->where('first_name', 'like', '%'.$search.'%')
                            ->orWhere('last_name', 'like', '%'.$search.'%')
                            ->orWhere('username', 'like', '%'.ltrim($search, '@').'%'),
                    )),
                Tables\Columns\TextColumn::make('learner.telegram_user_id')->label('Telegram ID')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('seat_state')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'seated' => 'success',
                        'waitlisted' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('joined_via')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('joined_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('waitlisted_at')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('left_at')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(['active' => 'Active', 'left' => 'Left', 'removed' => 'Removed'])
                    ->default('active'),
                SelectFilter::make('seat_state')
                    ->options(['seated' => 'Seated', 'waitlisted' => 'Waitlisted', 'none' => 'None']),
            ])
            ->headerActions([
                Action::make('addMember')
                    ->label('Add learner')
                    ->icon('heroicon-o-user-plus')
                    ->authorize(fn (): bool => Gate::allows('create', OrganizationMember::class))
                    ->schema([
                        Forms\Components\Select::make('learner_id')
                            ->label('Learner')
                            ->required()
                            ->searchable()
                            ->helperText('Search by name, @username or Telegram ID.')
                            ->getSearchResultsUsing(fn (string $search): array => $this->searchLearners($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => ($learner = Learner::query()->whereKey($value)->first()) === null ? null : $this->learnerLabel($learner)),
                    ])
                    ->action(function (array $data): void {
                        $learner = Learner::query()->whereKey($data['learner_id'] ?? 0)->first();
                        if ($learner === null) {
                            Notification::make()->title('Learner not found')->danger()->send();

                            return;
                        }

                        $service = app(OrganizationMembershipService::class);
                        $organization = $this->organization();

                        if ($service->activeMembership($learner, $organization) !== null) {
                            Notification::make()->title('Already a member')->warning()->send();

                            return;
                        }

                        try {
                            $member = $service->joinByAdmin($learner, $organization, $this->actor(), request()->ip());
                        } catch (OrganizationMembershipException $e) {
                            Notification::make()->title('Learner not added')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title($member->isSeated() ? 'Learner added and seated' : 'Learner added to the waiting list')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Action::make('removeMember')
                    ->label('Remove')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->visible(fn (OrganizationMember $record): bool => $record->isActive())
                    ->authorize(fn (OrganizationMember $record): bool => Gate::allows('update', $record))
                    ->requiresConfirmation()
                    ->modalDescription('The learner loses the seat (and the sponsored Pro) at once; the earliest waiting member gets it. Their progress stays.')
                    ->action(function (OrganizationMember $record): void {
                        $learner = Learner::withTrashed()->find($record->learner_id);
                        if ($learner === null) {
                            return;
                        }

                        try {
                            app(OrganizationMembershipService::class)->leave($learner, $this->organization(), 'removed', $this->actor(), request()->ip());
                        } catch (OrganizationMembershipException $e) {
                            Notification::make()->title('Already removed')->body($e->getMessage())->warning()->send();

                            return;
                        }

                        Notification::make()->title('Member removed')->success()->send();
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
            return '(deleted learner)';
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
