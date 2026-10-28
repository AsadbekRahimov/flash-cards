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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Invite links of an organization (docs/13 §1.3, §6.2): `t.me/<bot>?start=org_<code>`. Create and deactivate go
 * through {@see OrganizationInviteService}; a link is never deleted (members keep `invite_id`). The link column is
 * copyable; it needs `TELEGRAM_BOT_USERNAME`.
 */
class InvitesRelationManager extends RelationManager
{
    protected static string $relationship = 'invites';

    protected static ?string $recordTitleAttribute = 'code';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.organizations.invites.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('admin.organizations.invites.singular'))
            ->pluralModelLabel(__('admin.organizations.invites.plural'))
            ->emptyStateHeading(__('admin.organizations.invites.empty'))
            ->columns([
                Tables\Columns\TextColumn::make('label')->label(__('admin.organizations.invites.columns.label'))->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('code')->label(__('admin.organizations.invites.columns.code'))->copyable()->fontFamily('mono')->searchable(),
                Tables\Columns\TextColumn::make('link')
                    ->label(__('admin.organizations.invites.columns.link'))
                    ->state(fn (OrganizationInvite $record): ?string => $this->botConfigured() ? app(OrganizationInviteService::class)->link($record) : null)
                    ->placeholder(__('admin.organizations.invites.columns.link_placeholder'))
                    ->copyable(fn (): bool => $this->botConfigured())
                    ->copyMessage(__('admin.organizations.invites.columns.link_copied'))
                    ->limit(40),
                Tables\Columns\TextColumn::make('uses')
                    ->label(__('admin.organizations.invites.columns.uses'))
                    ->state(fn (OrganizationInvite $record): string => $record->uses_count.' / '.($record->max_uses ?? '∞')),
                Tables\Columns\TextColumn::make('expires_at')->label(__('admin.organizations.invites.columns.expires_at'))->dateTime()->placeholder(__('admin.organizations.invites.columns.never'))->sortable(),
                Tables\Columns\TextColumn::make('telegramGroup.title')->label(__('admin.organizations.invites.columns.group'))->placeholder('—')->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->label(__('admin.organizations.invites.columns.is_active'))->boolean(),
                Tables\Columns\TextColumn::make('created_at')->label(__('admin.organizations.invites.columns.created_at'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Action::make('createInvite')
                    ->label(__('admin.organizations.invites.actions.create'))
                    ->icon('heroicon-o-plus')
                    ->authorize(fn (): bool => Gate::allows('create', OrganizationInvite::class))
                    ->schema([
                        Forms\Components\TextInput::make('label')
                            ->label(__('admin.organizations.invites.columns.label'))
                            ->maxLength(64)
                            ->helperText(__('admin.organizations.invites.fields.label_help')),
                        Forms\Components\Select::make('telegram_group_id')
                            ->label(__('admin.organizations.invites.fields.group'))
                            ->options(fn (): array => TelegramGroup::query()
                                ->where('organization_id', $this->organization()->id)
                                ->orderBy('title')
                                ->pluck('title', 'id')
                                ->all())
                            ->helperText(__('admin.organizations.invites.fields.group_help')),
                        Forms\Components\TextInput::make('max_uses')->label(__('admin.organizations.invites.fields.max_uses'))->numeric()->integer()->minValue(1)->helperText(__('admin.organizations.invites.fields.max_uses_help')),
                        Forms\Components\DateTimePicker::make('expires_at')->label(__('admin.organizations.invites.fields.expires_at'))->minDate(now())->helperText(__('admin.organizations.invites.fields.expires_at_help')),
                    ])
                    ->action(function (array $data): void {
                        $organization = $this->organization();
                        $groupId = isset($data['telegram_group_id']) && $data['telegram_group_id'] !== '' ? (int) $data['telegram_group_id'] : null;

                        // The group must be the organization's own (a hand-made request could name any id).
                        if ($groupId !== null && ! TelegramGroup::query()->whereKey($groupId)->where('organization_id', $organization->id)->exists()) {
                            Notification::make()->title(__('admin.organizations.invites.notifications.not_created'))->body(__('admin.organizations.invites.notifications.foreign_group'))->danger()->send();

                            return;
                        }

                        $invite = app(OrganizationInviteService::class)->create($organization, $this->actor(), [
                            'label' => isset($data['label']) ? (string) $data['label'] : null,
                            'telegram_group_id' => $groupId,
                            'max_uses' => isset($data['max_uses']) && $data['max_uses'] !== '' ? (int) $data['max_uses'] : null,
                            'expires_at' => isset($data['expires_at']) && $data['expires_at'] !== '' ? CarbonImmutable::parse((string) $data['expires_at']) : null,
                        ]);

                        Notification::make()->title(__('admin.organizations.invites.notifications.created'))->body($this->botConfigured() ? app(OrganizationInviteService::class)->link($invite) : $invite->code)->success()->send();
                    }),
            ])
            ->actions([
                Action::make('deactivateInvite')
                    ->label(__('admin.organizations.invites.actions.deactivate'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (OrganizationInvite $record): bool => $record->is_active)
                    ->authorize(fn (OrganizationInvite $record): bool => Gate::allows('update', $record))
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.organizations.invites.actions.deactivate_help'))
                    ->action(function (OrganizationInvite $record): void {
                        app(OrganizationInviteService::class)->deactivate($record, $this->actor());

                        Notification::make()->title(__('admin.organizations.invites.notifications.deactivated'))->success()->send();
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
