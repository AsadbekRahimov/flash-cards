<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentResource\Support;

use App\Domain\Billing\Enums\SubscriptionProvider;
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
            ->modalDescription(fn (Payment $record): string => self::refundDescription($record))
            ->schema(fn (Payment $record): array => [
                Forms\Components\Textarea::make('note')->label('Reason')->required()->maxLength(500)->rows(2),
                Forms\Components\Toggle::make('returned_by_hand')
                    ->label('The money was already returned by hand in the provider\'s cabinet')
                    // Never on by default: the admin asserts explicitly that the money has really been returned.
                    ->helperText(self::manualOnly($record)
                        ? 'This provider cannot be refunded from here: switch this on only after the refund is done in its cabinet; only the local record is made.'
                        : 'Skips the call to the provider and only records the refund here.'),
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

    /** Payme and Click send no refund we could trigger: the admin refunds in the cabinet and records it here (docs/13 §4.2, §4.3). */
    private static function manualOnly(Payment $record): bool
    {
        return in_array($record->provider, [SubscriptionProvider::Payme->value, SubscriptionProvider::Click->value], true);
    }

    private static function refundDescription(Payment $record): string
    {
        $provider = match ($record->provider) {
            SubscriptionProvider::Click->value => 'Click sends no refund notifications: return the money in the Click cabinet first, then confirm it below — only the record is made here.',
            SubscriptionProvider::Payme->value => 'Payme refunds made in its cabinet usually arrive as CancelTransaction by themselves; use this only when they did not: return the money in the Payme cabinet first, then confirm it below.',
            default => 'Telegram Stars are returned through the Bot API.',
        };

        return $provider.' The subscription ends now if this payment paid for the running period. The reason is stored in the audit log.';
    }
}
