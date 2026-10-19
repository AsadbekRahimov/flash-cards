<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProviderTransaction;
use App\Models\User;

/** Provider transactions: read-only, active admins only (see PaymentPolicy). */
final class ProviderTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, ProviderTransaction $transaction): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ProviderTransaction $transaction): bool
    {
        return false;
    }

    public function delete(User $user, ProviderTransaction $transaction): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function manage(User $user): bool
    {
        return $user->is_active && $user->hasRole('admin');
    }
}
