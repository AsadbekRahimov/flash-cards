<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

/**
 * Billing ledger screens are read-only and for an active admin only (docs/13 §10). Rows are
 * written by the payment services; nothing is created, edited or deleted from the panel.
 */
final class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Payment $payment): bool
    {
        return false;
    }

    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /** The "Refund" action; whether the row is refundable at all is decided by PaymentRefundService. */
    public function refund(User $user, Payment $payment): bool
    {
        return $this->manage($user);
    }

    private function manage(User $user): bool
    {
        return $user->is_active && $user->hasRole('admin');
    }
}
