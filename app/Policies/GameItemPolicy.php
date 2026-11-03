<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GameItem;
use App\Models\User;

/**
 * The catalogue of the shop of the game (docs/18 §12.10): written, published and archived by an active platform admin only. Nothing is
 * deleted: bought items stay in the inventories, so an item goes to the archive.
 */
final class GameItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, GameItem $item): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, GameItem $item): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, GameItem $item): bool
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
