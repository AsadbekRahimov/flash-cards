<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources;

use App\Domain\Organizations\Exceptions\OrgGroupLinkException;
use App\Domain\Organizations\Services\OrgGroupLinkService;
use App\Filament\Org\Resources\GroupResource\Pages;
use App\Filament\Resources\Support\AdminTable;
use App\Models\TelegramGroup;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\HtmlString;

/**
 * Telegram groups of the organization (docs/13 §6.2–6.3): the owner links a group through the bot — «Привязать группу» issues a
 * one-time `t.me/<bot>?startgroup=grp_<token>` link (or `/link <code>` for a group where the bot already is); the bot links the group
 * only when the sender is the owner's verified Telegram account AND creator / administrator of the chat (OrgGroupLinkService) — unlinks
 * it, and picks its course (assignments need one); a teacher sees only the groups they are assigned to.
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
        // Page sizes 10/25/50/100 and nothing else (docs/20 §1.6): a forged $tableRecordsPerPage gets the default, not every row.
        return AdminTable::bounded($table)
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
                // Issues the one-time link when the modal opens (that click IS the request) and keeps it in the mounted action's
                // data: a re-render must not issue another link and invalidate the one on screen. The group appears in the list
                // once the bot has linked it from the chat (OrgGroupLinkService::linkFromChat).
                Actions\Action::make('link')
                    ->label(__('org.groups.actions.link'))
                    ->icon('heroicon-o-link')
                    ->authorize(fn (): bool => static::isOwner())
                    ->modalDescription(__('org.groups.actions.link_help'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('filament::components/modal.actions.close.label'))
                    ->mountUsing(function (Schema $schema, Actions\Action $action): void {
                        // Read BEFORE issuing: a new link replaces the owner's live token, and with it the reason of the last refusal.
                        $lastAttempt = self::lastAttemptText();

                        try {
                            $link = app(OrgGroupLinkService::class)->issueLink(static::tenant(), static::user());
                        } catch (OrgGroupLinkException $e) {
                            Notification::make()->title(__('org.groups.errors.'.$e->errorCode))->danger()->send();
                            $action->cancel();

                            return;
                        }

                        $schema->fill([
                            'url' => $link->url,
                            'command' => $link->command(),
                            'expires' => $link->expiresAt->setTimezone(static::tenant()->timezone)->format('H:i'),
                            'last_attempt' => $lastAttempt,
                        ]);
                    })
                    ->schema([
                        Html::make(fn (Get $get): HtmlString => new HtmlString(view('filament.org.partials.open-telegram', [
                            'url' => (string) $get('url'),
                            'label' => __('org.groups.fields.link_open'),
                        ])->render())),
                        Forms\Components\TextInput::make('url')
                            ->label(__('org.groups.fields.link_url'))
                            ->readOnly()
                            ->copyable(copyMessage: __('org.groups.fields.copied'))
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('command')
                            ->label(__('org.groups.fields.link_command'))
                            ->readOnly()
                            ->copyable(copyMessage: __('org.groups.fields.copied'))
                            ->dehydrated(false),
                        Text::make(fn (Get $get): string => __('org.groups.fields.link_expires', ['time' => (string) $get('expires')])),
                        Text::make(fn (Get $get): string => (string) $get('last_attempt')),
                    ]),
            ])
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\Action::make('course')
                    ->label(__('org.groups.actions.course'))
                    ->icon('heroicon-o-book-open')
                    ->modalDescription(__('org.groups.actions.course_help'))
                    ->authorize(fn (TelegramGroup $record): bool => static::canEdit($record))
                    ->fillForm(fn (TelegramGroup $record): array => ['course_id' => $record->course_id])
                    ->schema([
                        Forms\Components\Select::make('course_id')
                            ->label(__('org.groups.fields.course'))
                            ->options(fn (): array => app(OrgGroupLinkService::class)->courseOptions(static::tenant()))
                            ->required(),
                    ])
                    ->action(function (TelegramGroup $record, array $data): void {
                        try {
                            $group = app(OrgGroupLinkService::class)->setCourse(static::tenant(), $record, (int) $data['course_id'], static::user(), static::ip());
                        } catch (OrgGroupLinkException $e) {
                            Notification::make()->title(__('org.groups.errors.'.$e->errorCode))->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('org.groups.notifications.course_saved', ['title' => $group->title]))->success()->send();
                    }),
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
            // Resolved through getEloquentQuery(), so the record already carries the `withCount` value (docs/20 A10).
            Infolists\Components\TextEntry::make('active_members_count')
                ->label(__('org.groups.columns.learners')),
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

    /**
     * What the owner's previous link attempt came to — the bot answers neutrally in the chat, the reason lives on the token row
     * (`GroupLinkResult`). Read before the modal issues a new link (which replaces the live token).
     */
    private static function lastAttemptText(): string
    {
        $previous = app(OrgGroupLinkService::class)->lastAttempt(static::tenant(), static::user());

        if ($previous === null || $previous->last_result === null) {
            return '';
        }

        $key = 'org.groups.link_result.'.$previous->last_result;

        return __('org.groups.link_result.title').': '.(Lang::has($key) ? __($key) : $previous->last_result);
    }
}
