<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources;

use App\Domain\Organizations\Exceptions\OrgGroupLinkException;
use App\Domain\Organizations\Services\OrgGroupLinkService;
use App\Filament\Org\Resources\GroupResource\Pages;
use App\Models\TelegramGroup;
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
 * Telegram groups of the organization (docs/13 §6.2–6.3): the owner links a group by its chat id
 * (the bot must already be in it and the owner must be its creator / administrator — checked
 * with `getChatMember`) and unlinks it; a teacher sees only the groups they are assigned to.
 */
class GroupResource extends OrgResource
{
    protected static ?string $model = TelegramGroup::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationLabel(): string
    {
        return __('org.nav.groups');
    }

    public static function getModelLabel(): string
    {
        return __('org.groups.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('org.groups.plural');
    }

    /** @return Builder<TelegramGroup> */
    public static function getEloquentQuery(): Builder
    {
        return static::scope()->groups(static::user(), static::tenant())
            ->with('teachers')
            ->withCount(['members as active_members_count' => fn (Builder $q): Builder => $q->where('group_members.status', 'active')]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label(__('org.groups.columns.title'))->searchable()->limit(40),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('org.groups.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('org.groups.status.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'pending' => 'warning',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('active_members_count')->label(__('org.groups.columns.learners')),
                Tables\Columns\TextColumn::make('teachers.name')
                    ->label(__('org.groups.columns.teachers'))
                    ->listWithLineBreaks()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label(__('org.groups.columns.since'))->date('d.m.Y')->sortable(),
            ])
            ->headerActions([
                Actions\Action::make('link')
                    ->label(__('org.groups.actions.link'))
                    ->icon('heroicon-o-link')
                    ->authorize(fn (): bool => static::isOwner())
                    ->modalDescription(__('org.groups.actions.link_help'))
                    ->schema([
                        Forms\Components\TextInput::make('chat_id')
                            ->label(__('org.groups.fields.chat_id'))
                            ->helperText(__('org.groups.fields.chat_id_help'))
                            ->required()
                            ->regex('/^-?\d{1,20}$/'),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $group = app(OrgGroupLinkService::class)->link(static::tenant(), (int) $data['chat_id'], static::user(), static::ip());
                        } catch (OrgGroupLinkException $e) {
                            Notification::make()->title(__('org.groups.errors.'.$e->errorCode))->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('org.groups.notifications.linked', ['title' => $group->title]))->success()->send();
                    }),
            ])
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\Action::make('unlink')
                    ->label(__('org.groups.actions.unlink'))
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('org.groups.actions.unlink_confirm'))
                    ->authorize(fn (TelegramGroup $record): bool => static::canEdit($record))
                    ->action(function (TelegramGroup $record): void {
                        try {
                            app(OrgGroupLinkService::class)->unlink(static::tenant(), $record, static::user(), static::ip());
                        } catch (OrgGroupLinkException $e) {
                            Notification::make()->title(__('org.groups.errors.'.$e->errorCode))->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('org.groups.notifications.unlinked'))->success()->send();
                    }),
            ])
            ->defaultSort('title');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Infolists\Components\TextEntry::make('title')->label(__('org.groups.columns.title')),
            Infolists\Components\TextEntry::make('chat_id')->label(__('org.groups.fields.chat_id'))->copyable(),
            Infolists\Components\TextEntry::make('status')
                ->label(__('org.groups.columns.status'))
                ->badge()
                ->formatStateUsing(fn (string $state): string => __('org.groups.status.'.$state)),
            Infolists\Components\TextEntry::make('active_members_count')
                ->label(__('org.groups.columns.learners'))
                ->state(fn (TelegramGroup $record): int => $record->members()->where('status', 'active')->count()),
            Infolists\Components\TextEntry::make('teachers.name')->label(__('org.groups.columns.teachers'))->listWithLineBreaks()->placeholder('—'),
            Infolists\Components\TextEntry::make('created_at')->label(__('org.groups.columns.since'))->dateTime('d.m.Y H:i'),
        ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGroups::route('/'),
            'view' => Pages\ViewGroup::route('/{record}'),
        ];
    }
}
