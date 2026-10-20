<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrganizationResource\RelationManagers;

use App\Domain\Organizations\Services\OrganizationInviteService;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

/**
 * Invite links of an organization (docs/13 §1.3, §6.2): `t.me/<bot>?start=org_<code>`. Create and deactivate go
 * through {@see OrganizationInviteService}; a link is never deleted (members keep `invite_id`). The link column is
 * copyable; it needs `TELEGRAM_BOT_USERNAME`.
 */
class InvitesRelationManager extends RelationManager
{
    protected static string $relationship = 'invites';

    protected static ?string $title = 'Invites';

    protected static ?string $recordTitleAttribute = 'code';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('code')->copyable()->fontFamily('mono')->searchable(),
                Tables\Columns\TextColumn::make('link')
                    ->label('Link')
                    ->state(fn (OrganizationInvite $record): ?string => $this->botConfigured() ? app(OrganizationInviteService::class)->link($record) : null)
                    ->placeholder('Set TELEGRAM_BOT_USERNAME')
                    ->copyable(fn (): bool => $this->botConfigured())
                    ->copyMessage('Link copied')
                    ->limit(40),
                Tables\Columns\TextColumn::make('uses')
                    ->label('Uses')
                    ->state(fn (OrganizationInvite $record): string => $record->uses_count.' / '.($record->max_uses ?? '∞')),
                Tables\Columns\TextColumn::make('expires_at')->dateTime()->placeholder('never')->sortable(),
                Tables\Columns\TextColumn::make('telegramGroup.title')->label('Group')->placeholder('—')->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Action::make('createInvite')
                    ->label('Create invite')
                    ->icon('heroicon-o-plus')
                    ->authorize(fn (): bool => Gate::allows('create', OrganizationInvite::class))
                    ->schema([
                        Forms\Components\TextInput::make('label')
                            ->maxLength(64)
                            ->helperText('A note for yourself, e.g. «group 7B».'),
                        Forms\Components\Select::make('telegram_group_id')
                            ->label('Group (label only)')
                            ->options(fn (): array => TelegramGroup::query()
                                ->where('organization_id', $this->organization()->id)
                                ->orderBy('title')
                                ->pluck('title', 'id')
                                ->all())
                            ->helperText('Joining a Telegram chat is still done by the person; this is only a mark.'),
                        Forms\Components\TextInput::make('max_uses')->numeric()->integer()->minValue(1)->helperText('Empty = unlimited.'),
                        Forms\Components\DateTimePicker::make('expires_at')->minDate(now())->helperText('Empty = never expires.'),
                    ])
                    ->action(function (array $data): void {
                        $organization = $this->organization();
                        $groupId = isset($data['telegram_group_id']) && $data['telegram_group_id'] !== '' ? (int) $data['telegram_group_id'] : null;

                        // The group must be the organization's own (a hand-made request could name any id).
                        if ($groupId !== null && ! TelegramGroup::query()->whereKey($groupId)->where('organization_id', $organization->id)->exists()) {
                            Notification::make()->title('Invite not created')->body('The group does not belong to this organization.')->danger()->send();

                            return;
                        }

                        $invite = app(OrganizationInviteService::class)->create($organization, $this->actor(), [
                            'label' => isset($data['label']) ? (string) $data['label'] : null,
                            'telegram_group_id' => $groupId,
                            'max_uses' => isset($data['max_uses']) && $data['max_uses'] !== '' ? (int) $data['max_uses'] : null,
                            'expires_at' => isset($data['expires_at']) && $data['expires_at'] !== '' ? CarbonImmutable::parse((string) $data['expires_at']) : null,
                        ]);

                        Notification::make()->title('Invite created')->body($this->botConfigured() ? app(OrganizationInviteService::class)->link($invite) : $invite->code)->success()->send();
                    }),
            ])
            ->actions([
                Action::make('deactivateInvite')
                    ->label('Deactivate')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (OrganizationInvite $record): bool => $record->is_active)
                    ->authorize(fn (OrganizationInvite $record): bool => Gate::allows('update', $record))
                    ->requiresConfirmation()
                    ->modalDescription('The link stops working at once. Members who already joined stay.')
                    ->action(function (OrganizationInvite $record): void {
                        app(OrganizationInviteService::class)->deactivate($record, $this->actor());

                        Notification::make()->title('Invite deactivated')->success()->send();
                    }),
            ])
            ->defaultSort('id', 'desc');
    }

    private function botConfigured(): bool
    {
        return ltrim(trim((string) config('telegram.bot_username', '')), '@') !== '';
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
