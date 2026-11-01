<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PracticeItem;
use App\Models\User;

/**
 * The tasks of the practice (docs/16 §12): written, reviewed, published and archived by an active platform admin only.
 * A teacher gets 403 and the cabinet of a center has no such screen. Nothing is deleted: a task goes to the archive
 * (the sessions and matches that hold a version keep playing it).
 */
final class PracticeItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, PracticeItem $item): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, PracticeItem $item): bool
    {
        return $this->manage($user);
    }

    /** "Reviewed" - the text has been checked. */
    public function review(User $user, PracticeItem $item): bool
    {
        return $this->manage($user);
    }

    public function publish(User $user, PracticeItem $item): bool
    {
        return $this->manage($user);
    }

    public function archive(User $user, PracticeItem $item): bool
    {
        return $this->manage($user);
    }

    public function restore(User $user, PracticeItem $item): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, PracticeItem $item): bool
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
