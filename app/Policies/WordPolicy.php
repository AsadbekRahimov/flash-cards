<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Word;

final class WordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasAnyRole(['admin', 'teacher']);
    }

    /** The content of a learner's private deck is for admins only (docs/13 §8.1). */
    public function view(User $user, Word $word): bool
    {
        return $this->viewAny($user) && ($user->hasRole('admin') || ! $word->lesson->stage->course->isLearnerOwned());
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->hasRole('admin');
    }

    public function update(User $user, Word $word): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Word $word): bool
    {
        return false;
    }
}
