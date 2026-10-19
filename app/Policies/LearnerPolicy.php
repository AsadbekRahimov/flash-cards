<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Learner;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class LearnerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasAnyRole(['admin', 'teacher']);
    }

    /**
     * Admin: any learner. Teacher: only a learner with an ACTIVE membership in
     * one of their groups.
     */
    public function view(User $user, Learner $learner): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if ($user->hasRole('admin')) {
            return true;
        }

        return $learner->memberships()
            ->where('status', 'active')
            ->whereHas(
                'group.teachers',
                fn (Builder $teachers): Builder => $teachers->whereKey($user->id),
            )
            ->exists();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Learner $learner): bool
    {
        return $user->is_active && $user->hasRole('admin');
    }

    public function delete(User $user, Learner $learner): bool
    {
        return false;
    }
}
