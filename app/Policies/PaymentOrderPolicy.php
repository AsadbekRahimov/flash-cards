<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PaymentOrder;
use App\Models\User;

/** Payment orders: read-only, active admins only (see PaymentPolicy). */
final class PaymentOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, PaymentOrder $order): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PaymentOrder $order): bool
    {
        return false;
    }

    public function delete(User $user, PaymentOrder $order): bool
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
