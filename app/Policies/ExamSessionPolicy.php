<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Learners\Services\GroupMembershipService;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\User;

final class ExamSessionPolicy
{
    public function __construct(private readonly GroupMembershipService $memberships) {}

    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasAnyRole(['admin', 'teacher']);
    }

    public function view(User $user, ExamSession $session): bool
    {
        return $this->viewAny($user)
            && ($user->hasRole('admin') || $session->group?->teachers()->whereKey($user->id)->exists() === true);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ExamSession $session): bool
    {
        return false;
    }

    public function delete(User $user, ExamSession $session): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /**
     * Error code to answer with (403) when the learner may not see this exam,
     * null when access is granted: an active member of the session's active
     * group (`not_a_member` / `group_inactive`; 410 for a closed session is
     * decided separately).
     */
    public function denial(Learner $learner, ExamSession $session): ?string
    {
        if ($learner->blocked_at !== null) {
            return 'forbidden';
        }

        return $this->memberships->denialReason($learner, $session->group);
    }

    /**
     * The learner is an active member of the exam's group. Use this when we want
     * to distinguish "you don't belong here" (403) from "session closed" (410).
     */
    public function canAccess(Learner $learner, ExamSession $session): bool
    {
        return $this->denial($learner, $session) === null;
    }

    /**
     * The learner can join/answer — the session must additionally still be open.
     */
    public function canParticipate(Learner $learner, ExamSession $session): bool
    {
        return $this->canAccess($learner, $session)
            && $session->status === 'open'
            && ($session->ends_at === null || $session->ends_at->isFuture());
    }

    /**
     * Results are viewable only AFTER the session is closed (so learners
     * don't see partial leaderboards mid-exam).
     */
    public function canViewResult(Learner $learner, ExamSession $session): bool
    {
        return $this->canAccess($learner, $session) && $session->status === 'closed';
    }
}
