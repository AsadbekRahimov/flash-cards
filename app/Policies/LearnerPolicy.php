<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Administration\Services\PanelDataScope;
use App\Models\Learner;
use App\Models\User;

final class LearnerPolicy
{
    public function __construct(private readonly PanelDataScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasAnyRole(['admin', 'teacher']);
    }

    /**
     * Admin: any learner. Teacher: only a learner with an ACTIVE membership in one of their groups — answered by
     * PanelDataScope from the teacher's groups (read once per request) and the memberships the list loaded, so the
     * link, the view button and the actions of every row of a list cost no statement (docs/20 A11).
     */
    public function view(User $user, Learner $learner): bool
    {
        return $this->viewAny($user) && $this->scope->canViewLearner($user, $learner);
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
