<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GameRun;
use App\Models\User;

/** The runs of the game (docs/18 §12.10): read-only, for an active platform admin only; nobody writes a run from the panel. */
final class GameRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->read($user);
    }

    public function view(User $user, GameRun $run): bool
    {
        return $this->read($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, GameRun $run): bool
    {
        return false;
    }

    public function delete(User $user, GameRun $run): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function read(User $user): bool
    {
        return $user->is_active && $user->hasRole('admin');
    }
}
