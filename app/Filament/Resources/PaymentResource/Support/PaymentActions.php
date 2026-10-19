<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentResource\Support;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\PaymentRefundService;
use App\Models\Payment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;

/**
 * "Refund" of a succeeded charge / renewal. Goes through {@see PaymentRefundService}, which calls the
 * provider (when it can), records the refund in the ledger with an audit entry and ends the
 * subscription when the refunded charge paid for the running period. The action only collects the
 * reason and reports a {@see BillingException} as a notification.
 */
final class PaymentActions
{
    public static function refund(): Action
    {
        return Action::make('refund')
            ->label('Refund')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (Payment $record): bool => $record->status === Payment::STATUS_SUCCEEDED
                && in_array($record->kind, [Payment::KIND_CHARGE, Payment::KIND_RENEWAL], true))
            ->authorize('refund')
            ->requiresConfirmation()
            ->modalHeading('Refund this payment')
            ->modalDescription('Telegram Stars are returned through the Bot API. Payme / Click cannot be refunded from here: return the money in their cabinet first, then confirm it below. The subscription ends now if this payment paid for the running period. The reason is stored in the audit log.')
            ->schema([
                Forms\Components\Textarea::make('note')->label('Reason')->required()->maxLength(500)->rows(2),
                Forms\Components\Toggle::make('returned_by_hand')
                    ->label('The money was already returned by hand in the provider\'s cabinet')
                    ->helperText('Skips the call to the provider and only records the refund here.'),
            ])
            ->action(function (Payment $record, array $data): void {
                $user = auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                try {
                    app(PaymentRefundService::class)->refund($record, $user, (string) $data['note'], (bool) ($data['returned_by_hand'] ?? false), request()->ip());
                } catch (BillingException $e) {
                    Notification::make()->title('Not refunded')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title('Payment refunded')->success()->send();
            });
    }
}
