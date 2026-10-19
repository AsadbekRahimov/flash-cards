<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PromoCode;
use App\Models\User;

/** Promo codes: active admins only. A code that was redeemed is deactivated, never deleted (its redemptions are history). */
final class PromoCodePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, PromoCode $promo): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, PromoCode $promo): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, PromoCode $promo): bool
    {
        return $this->manage($user) && $promo->redemptions_count === 0;
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
