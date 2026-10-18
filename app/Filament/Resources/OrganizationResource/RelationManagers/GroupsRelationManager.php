<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrganizationResource\RelationManagers;

use App\Domain\Administration\Services\OrganizationGroupLinker;
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
use Illuminate\Support\Facades\Gate;

/**
 * Telegram groups of an organization. Link / unlink go through {@see OrganizationGroupLinker}
 * (Eloquent save -> TelegramGroupObserver drops the members' entitlement cache, plus the audit
 * record), not through the stock associate / dissociate actions.
 */
class GroupsRelationManager extends RelationManager
{
    protected static string $relationship = 'telegramGroups';

    protected static ?string $title = 'Groups';

    protected static ?string $recordTitleAttribute = 'title';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable(),
                Tables\Columns\TextColumn::make('chat_id')->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('members_count')
                    ->label('Active members')
                    ->counts(['members' => fn ($query) => $query->where('group_members.status', 'active')]),
            ])
            ->headerActions([
                Action::make('linkGroup')
                    ->label('Link group')
                    ->icon('heroicon-o-link')
                    ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    ->schema([
                        Forms\Components\Select::make('telegram_group_id')
                            ->label('Group')
                            ->required()
                            ->searchable()
                            ->helperText('Only groups that do not belong to an organization yet.')
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
                            Notification::make()->title('Group not linked')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Group linked')->success()->send();
                    }),
            ])
            ->actions([
                Action::make('unlinkGroup')
                    ->label('Unlink')
                    ->icon('heroicon-o-link-slash')
                    ->color('danger')
                    ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    ->requiresConfirmation()
                    ->modalDescription('Members of the group lose the sponsored Pro (their own subscriptions stay).')
                    ->action(function (TelegramGroup $record): void {
                        app(OrganizationGroupLinker::class)->unlink($this->organization(), $record, $this->actor(), request()->ip());

                        Notification::make()->title('Group unlinked')->success()->send();
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
