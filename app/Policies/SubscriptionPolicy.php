<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;

/**
 * Subscriptions are platform money matters: only an active admin sees them and grants / revokes
 * (docs/12 §5.5). `create` = the "Grant Pro" action, `update` = "Revoke"; rows are never deleted.
 */
final class SubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, Subscription $subscription): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Subscription $subscription): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, Subscription $subscription): bool
    {
        return false;
    }

    private function manage(User $user): bool
    {
        return $user->is_active && $user->hasRole('admin');
    }
}
