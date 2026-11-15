<?php

declare(strict_types=1);

namespace App\Filament\Org\Widgets;

use App\Domain\Organizations\Enums\MemberInvitationState;
use App\Domain\Organizations\Exceptions\OrganizationMembershipException;
use App\Domain\Organizations\Services\OrganizationMembershipService;
use App\Domain\Organizations\Services\OrgTenantScope;
use App\Filament\Resources\Support\AdminTable;
use App\Models\Organization;
use App\Models\OrganizationMemberInvitation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Invitations" under the learners list of the `/org` cabinet (review finding B1 of the audit 2026-11-10): one row per attempt the
 * owner made, with WHAT THEY TYPED, the date and "waiting for an answer" / "expired" / "accepted" / "revoked" — and nothing
 * else. A handle that matched nobody, a learner who never answered, a learner who declined and a learner the bot could not
 * reach are the same row: no name, no activity, no counter that would tell who uses LexiFlow. Owner only (a teacher invites
 * nobody). Rows are read through {@see OrgTenantScope::memberInvitations()}; "revoke" goes through the membership service.
 */
class MemberInvitationsWidget extends TableWidget
{
    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = Filament::auth()->user();
        $tenant = Filament::getTenant();

        return $user instanceof User && $tenant instanceof Organization && app(OrgTenantScope::class)->isOwner($user, $tenant);
    }

    protected function getTableHeading(): string
    {
        return __('org.members.invitations.heading');
    }

    public function table(Table $table): Table
    {
        $now = CarbonImmutable::now();

        return AdminTable::bounded($table)
            ->query(fn (): Builder => app(OrgTenantScope::class)->memberInvitations($this->user(), $this->tenant()))
            ->description(__('org.members.invitations.description'))
            ->emptyStateHeading(__('org.members.invitations.empty'))
            ->columns([
                Tables\Columns\TextColumn::make('invited_handle')->label(__('org.members.invitations.columns.handle')),
                Tables\Columns\TextColumn::make('created_at')->label(__('org.members.invitations.columns.sent_at'))->dateTime('d.m.Y H:i'),
                Tables\Columns\TextColumn::make('state')
                    ->label(__('org.members.invitations.columns.state'))
                    ->badge()
                    ->state(fn (OrganizationMemberInvitation $record): string => $record->state($now)->value)
                    ->formatStateUsing(fn (string $state): string => __('org.members.invitations.state.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        MemberInvitationState::Pending->value => 'warning',
                        MemberInvitationState::Accepted->value => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label(__('org.members.invitations.columns.expires_at'))
                    ->dateTime('d.m.Y')
                    ->visibleFrom('md'),
            ])
            ->recordActions([
                Actions\Action::make('revoke')
                    ->label(__('org.members.actions.revoke'))
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(__('org.members.actions.revoke_confirm'))
                    ->visible(fn (OrganizationMemberInvitation $record): bool => $record->isOpenAt($now))
                    ->action(function (OrganizationMemberInvitation $record): void {
                        try {
                            app(OrganizationMembershipService::class)->revokeInvite($this->tenant(), $record, $this->user(), request()->ip());
                        } catch (OrganizationMembershipException $e) {
                            Notification::make()->title(__('org.members.errors.'.$e->errorCode))->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('org.members.notifications.revoked'))->success()->send();
                    }),
            ])
            ->defaultSort('id', 'desc');
    }

    private function tenant(): Organization
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Organization) {
            abort(404);
        }

        return $tenant;
    }

    private function user(): User
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
