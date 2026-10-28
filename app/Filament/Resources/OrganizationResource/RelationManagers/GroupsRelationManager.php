<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrganizationResource\RelationManagers;

use App\Domain\Administration\Services\OrganizationGroupLinker;
use App\Filament\Resources\Support\AdminLabels;
use App\Models\Organization;
use App\Models\TelegramGroup;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Telegram groups of an organization. Link / unlink go through {@see OrganizationGroupLinker}
 * (Eloquent save -> TelegramGroupObserver drops the members' entitlement cache, plus the audit
 * record), not through the stock associate / dissociate actions.
 */
class GroupsRelationManager extends RelationManager
{
    protected static string $relationship = 'telegramGroups';

    protected static ?string $recordTitleAttribute = 'title';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.organizations.groups.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('admin.organizations.groups.singular'))
            ->pluralModelLabel(__('admin.organizations.groups.plural'))
            ->emptyStateHeading(__('admin.organizations.groups.empty'))
            ->columns([
                Tables\Columns\TextColumn::make('title')->label(__('admin.organizations.groups.columns.title'))->searchable(),
                Tables\Columns\TextColumn::make('chat_id')->label(__('admin.organizations.groups.columns.chat_id'))->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.organizations.groups.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('telegram_groups.status', $state))
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('members_count')
                    ->label(__('admin.organizations.groups.columns.members_count'))
                    ->counts(['members' => fn ($query) => $query->where('group_members.status', 'active')]),
            ])
            ->headerActions([
                Action::make('linkGroup')
                    ->label(__('admin.organizations.groups.actions.link'))
                    ->icon('heroicon-o-link')
                    ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    ->schema([
                        Forms\Components\Select::make('telegram_group_id')
                            ->label(__('admin.organizations.groups.actions.link_field'))
                            ->required()
                            ->searchable()
                            ->helperText(__('admin.organizations.groups.actions.link_help'))
                            ->getSearchResultsUsing(fn (string $search): array => TelegramGroup::query()
                                ->whereNull('organization_id')
                                ->where('title', 'like', '%'.$search.'%')
                                ->orderBy('title')
                                ->limit(30)
                                ->pluck('title', 'id')
                                ->all())
                            ->getOptionLabelUsing(fn (mixed $value): ?string => TelegramGroup::query()->whereKey($value)->first()?->title),
                    ])
                    ->action(function (array $data): void {
                        $group = TelegramGroup::query()->whereKey($data['telegram_group_id'] ?? 0)->first();
                        if ($group === null) {
                            return;
                        }

                        try {
                            app(OrganizationGroupLinker::class)->link($this->organization(), $group, $this->actor(), request()->ip());
                        } catch (DomainException $e) {
                            Notification::make()->title(__('admin.organizations.groups.notifications.link_failed'))->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('admin.organizations.groups.notifications.linked'))->success()->send();
                    }),
            ])
            ->actions([
                Action::make('unlinkGroup')
                    ->label(__('admin.organizations.groups.actions.unlink'))
                    ->icon('heroicon-o-link-slash')
                    ->color('danger')
                    ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.organizations.groups.actions.unlink_help'))
                    ->action(function (TelegramGroup $record): void {
                        app(OrganizationGroupLinker::class)->unlink($this->organization(), $record, $this->actor(), request()->ip());

                        Notification::make()->title(__('admin.organizations.groups.notifications.unlinked'))->success()->send();
                    }),
            ])
            ->defaultSort('title');
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
