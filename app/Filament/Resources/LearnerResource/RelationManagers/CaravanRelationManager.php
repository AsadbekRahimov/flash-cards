<?php

declare(strict_types=1);

namespace App\Filament\Resources\LearnerResource\RelationManagers;

use App\Domain\Game\Enums\LedgerKind;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\Economy\TicketService;
use App\Domain\Game\Services\Economy\WalletAdjustmentService;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Models\GameCoinLedgerEntry;
use App\Models\GameProfile;
use App\Models\GameWallet;
use App\Models\Learner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The "Караван" tab of a learner (docs/18 §12.10): the balance of coins, the tickets and the last 50 entries of the ledger, with the
 * manual moves "Начислить монеты" / "Списать монеты". Admin only. Every move is audited FIRST (`game.coins_adjusted`) and keyed
 * `admin:{audit_log_id}` - that is {@see WalletAdjustmentService}'s; a debit below zero is refused with a notification.
 * "Выдать билеты" goes through {@see TicketService::grantByAdmin()} (its own audit record `game.tickets_adjusted`).
 */
class CaravanRelationManager extends RelationManager
{
    use RequiresActiveAdmin;

    /** The lines of the ledger the tab shows. */
    public const LEDGER_LINES = 50;

    protected static string $relationship = 'gameCoinLedger';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.game.wallet.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::isActiveAdmin();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('admin.game.wallet.singular'))
            ->pluralModelLabel(__('admin.game.wallet.plural'))
            ->heading(__('admin.game.wallet.title'))
            ->description(fn (): string => $this->summary())
            ->emptyStateHeading(__('admin.game.wallet.empty'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->limit(self::LEDGER_LINES))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label(__('admin.game.wallet.columns.created_at'))->dateTime(),
                Tables\Columns\TextColumn::make('amount')
                    ->label(__('admin.game.wallet.columns.amount'))
                    ->formatStateUsing(fn (int $state): string => ($state > 0 ? '+' : '').$state)
                    ->color(fn (int $state): string => $state >= 0 ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('kind')
                    ->label(__('admin.game.wallet.columns.kind'))
                    ->badge()
                    ->formatStateUsing(fn (LedgerKind $state): string => (string) __('game.ledger.'.$state->value, [], 'ru')),
                Tables\Columns\TextColumn::make('balance_after')->label(__('admin.game.wallet.columns.balance_after')),
                Tables\Columns\TextColumn::make('reason')
                    ->label(__('admin.game.wallet.columns.reason'))
                    ->state(fn (GameCoinLedgerEntry $record): ?string => is_string($record->meta['reason'] ?? null) ? $record->meta['reason'] : null)
                    ->placeholder('—'),
            ])
            ->headerActions([
                $this->adjustAction('grant', credit: true),
                $this->adjustAction('revoke', credit: false),
                $this->ticketsAction(),
            ])
            ->paginated(false)
            ->defaultSort('id', 'desc');
    }

    private function adjustAction(string $name, bool $credit): Action
    {
        return Action::make($name)
            ->label(__("admin.game.wallet.actions.{$name}.label"))
            ->icon($credit ? 'heroicon-o-plus-circle' : 'heroicon-o-minus-circle')
            ->color($credit ? 'success' : 'danger')
            ->visible(fn (): bool => self::isActiveAdmin())
            ->requiresConfirmation()
            ->modalHeading(__("admin.game.wallet.actions.{$name}.heading"))
            ->modalDescription(__('admin.game.wallet.actions.help'))
            ->schema([
                TextInput::make('amount')
                    ->label(__('admin.game.wallet.actions.amount'))
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(WalletAdjustmentService::MAX_AMOUNT)
                    ->required(),
                Textarea::make('reason')
                    ->label(__('admin.game.wallet.actions.reason'))
                    ->rows(2)
                    ->required()
                    ->maxLength(WalletAdjustmentService::MAX_REASON),
            ])
            ->action(function (array $data) use ($credit): void {
                $actor = auth()->user();
                $learner = $this->getOwnerRecord();
                if (! $actor instanceof User || ! $learner instanceof Learner) {
                    return;
                }

                try {
                    $entry = app(WalletAdjustmentService::class)->adjust($learner, $actor, (int) $data['amount'], trim((string) $data['reason']), $credit, request()->ip());
                } catch (GameException $e) {
                    Notification::make()
                        ->title(__('admin.game.wallet.notifications.refused'))
                        ->body(__('admin.game.wallet.notifications.insufficient', ['balance' => $e->details['balance'] ?? 0, 'amount' => $e->details['price'] ?? 0]))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__($credit ? 'admin.game.wallet.notifications.granted' : 'admin.game.wallet.notifications.revoked'))
                    ->body(__('admin.game.wallet.notifications.balance', ['balance' => $entry->balance]))
                    ->success()
                    ->send();
            });
    }

    /**
     * «Выдать билеты»: a positive number grants under the cap `tickets.max`, a negative one takes (never below 0). The audit entry
     * `game.tickets_adjusted` is written first by {@see TicketService::grantByAdmin()}.
     */
    private function ticketsAction(): Action
    {
        return Action::make('tickets')
            ->label(__('admin.game.wallet.actions.tickets.label'))
            ->icon('heroicon-o-ticket')
            ->color('warning')
            ->visible(fn (): bool => self::isActiveAdmin())
            ->requiresConfirmation()
            ->modalHeading(__('admin.game.wallet.actions.tickets.heading'))
            ->modalDescription(__('admin.game.wallet.actions.tickets.help', ['max' => (int) config('game.tickets.max')]))
            ->schema([
                TextInput::make('delta')
                    ->label(__('admin.game.wallet.actions.tickets.delta'))
                    ->numeric()
                    ->integer()
                    ->minValue(-100)
                    ->maxValue(100)
                    ->notIn([0])
                    ->required(),
                Textarea::make('reason')
                    ->label(__('admin.game.wallet.actions.reason'))
                    ->rows(2)
                    ->required()
                    ->maxLength(WalletAdjustmentService::MAX_REASON),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                $learner = $this->getOwnerRecord();
                if (! $actor instanceof User || ! $learner instanceof Learner) {
                    return;
                }

                $grant = app(TicketService::class)->grantByAdmin($learner, (int) $data['delta'], $actor, request()->ip(), trim((string) $data['reason']));

                Notification::make()
                    ->title(__('admin.game.wallet.notifications.tickets_done'))
                    ->body(__('admin.game.wallet.notifications.tickets_balance', ['balance' => $grant->balance]))
                    ->success()
                    ->send();

                if ($grant->lost > 0) {
                    Notification::make()
                        ->title(__('admin.game.wallet.notifications.tickets_lost', ['lost' => $grant->lost]))
                        ->info()
                        ->send();
                }
            });
    }

    /** «Баланс: 120 · Заработано: 300 · Потрачено: 180 · Билеты: 2». */
    private function summary(): string
    {
        $id = (int) $this->getOwnerRecord()->getKey();
        $wallet = GameWallet::query()->where('learner_id', $id)->first(['balance', 'earned_total', 'spent_total']);
        $tickets = (int) GameProfile::query()->where('learner_id', $id)->value('tickets');

        return __('admin.game.wallet.summary', [
            'balance' => $wallet->balance ?? 0,
            'earned' => $wallet->earned_total ?? 0,
            'spent' => $wallet->spent_total ?? 0,
            'tickets' => $tickets,
        ]);
    }
}
