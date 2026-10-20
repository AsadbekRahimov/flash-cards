<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources;

use App\Domain\Organizations\Services\OrganizationInviteService;
use App\Filament\Org\Resources\InviteResource\Pages;
use App\Models\OrganizationInvite;
use Carbon\CarbonImmutable;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Invite codes of the organization (docs/13 §1.3, §6.2–6.3): owner only. Create (label, uses,
 * expiry, optional group label), deactivate, copy the deep-link `t.me/<bot>?start=org_<code>`.
 * Codes are never edited or deleted — members keep `invite_id`.
 */
class InviteResource extends OrgResource
{
    protected static ?string $model = OrganizationInvite::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return __('org.nav.invites');
    }

    public static function getModelLabel(): string
    {
        return __('org.invites.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('org.invites.plural');
    }

    /** @return Builder<OrganizationInvite> */
    public static function getEloquentQuery(): Builder
    {
        return static::scope()->invites(static::user(), static::tenant())->with('telegramGroup');
    }

    public static function table(Table $table): Table
    {
        $invites = app(OrganizationInviteService::class);

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label(__('org.invites.columns.code'))->copyable()->fontFamily('mono'),
                Tables\Columns\TextColumn::make('label')->label(__('org.invites.columns.label'))->placeholder('—')->limit(32),
                Tables\Columns\TextColumn::make('telegramGroup.title')->label(__('org.invites.columns.group'))->placeholder('—')->limit(32)->toggleable(),
                Tables\Columns\TextColumn::make('uses')
                    ->label(__('org.invites.columns.uses'))
                    ->state(fn (OrganizationInvite $record): string => $record->uses_count.' / '.($record->max_uses ?? '∞')),
                Tables\Columns\TextColumn::make('expires_at')->label(__('org.invites.columns.expires_at'))->dateTime('d.m.Y H:i')->placeholder('—')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label(__('org.invites.columns.active'))->boolean(),
                Tables\Columns\TextColumn::make('link')
                    ->label(__('org.invites.columns.link'))
                    ->state(fn (OrganizationInvite $record): string => $invites->link($record))
                    ->copyable()
                    ->copyMessage(__('org.invites.copied'))
                    ->limit(36),
            ])
            ->headerActions([
                Actions\Action::make('create')
                    ->label(__('org.invites.actions.create'))
                    ->icon('heroicon-o-plus')
                    ->authorize(fn (): bool => static::canCreate())
                    ->schema([
                        Forms\Components\TextInput::make('label')->label(__('org.invites.fields.label'))->maxLength(64),
                        Forms\Components\TextInput::make('max_uses')->label(__('org.invites.fields.max_uses'))->numeric()->minValue(1)->maxValue(100000),
                        Forms\Components\DateTimePicker::make('expires_at')->label(__('org.invites.fields.expires_at'))->minDate(now()),
                        Forms\Components\Select::make('telegram_group_id')
                            ->label(__('org.invites.fields.group'))
                            ->helperText(__('org.invites.fields.group_help'))
                            ->options(fn (): array => static::scope()->groups(static::user(), static::tenant())->orderBy('title')->pluck('title', 'id')->all()),
                    ])
                    ->action(function (array $data) use ($invites): void {
                        $expiresAt = filled($data['expires_at'] ?? null) ? CarbonImmutable::parse((string) $data['expires_at']) : null;
                        $groupId = filled($data['telegram_group_id'] ?? null) ? (int) $data['telegram_group_id'] : null;

                        // The group must be the tenant's — the option list is scoped, this re-checks the submitted id.
                        if ($groupId !== null && ! static::scope()->groups(static::user(), static::tenant())->whereKey($groupId)->exists()) {
                            Notification::make()->title(__('org.invites.errors.foreign_group'))->danger()->send();

                            return;
                        }

                        $invite = $invites->create(static::tenant(), static::user(), [
                            'label' => isset($data['label']) ? (string) $data['label'] : null,
                            'max_uses' => filled($data['max_uses'] ?? null) ? (int) $data['max_uses'] : null,
                            'expires_at' => $expiresAt,
                            'telegram_group_id' => $groupId,
                        ]);

                        Notification::make()->title(__('org.invites.notifications.created', ['code' => $invite->code]))->success()->send();
                    }),
            ])
            ->recordActions([
                Actions\Action::make('deactivate')
                    ->label(__('org.invites.actions.deactivate'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (OrganizationInvite $record): bool => $record->is_active)
                    ->authorize(fn (OrganizationInvite $record): bool => static::canEdit($record))
                    ->action(function (OrganizationInvite $record) use ($invites): void {
                        $invites->deactivate($record, static::user());

                        Notification::make()->title(__('org.invites.notifications.deactivated'))->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvites::route('/'),
        ];
    }
}
