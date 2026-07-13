<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ExamSession;
use App\Models\Student;
use App\Models\User;

final class ExamSessionPolicy
{
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
     * Student is in the same group as the exam session. Use this when we want
     * to distinguish "you don't belong here" (403) from "session closed" (410).
     */
    public function canAccess(Student $student, ExamSession $session): bool
    {
        return $student->is_active
            && (int) $student->telegram_group_id === (int) $session->telegram_group_id;
    }

    /**
     * Student can join/answer — session must additionally still be open.
     */
    public function canParticipate(Student $student, ExamSession $session): bool
    {
        return $this->canAccess($student, $session)
            && $session->status === 'open'
            && ($session->ends_at === null || $session->ends_at->isFuture());
    }

    /**
     * Results are viewable only AFTER the session is closed (so students
     * don't see partial leaderboards mid-exam).
     */
    public function canViewResult(Student $student, ExamSession $session): bool
    {
        return $this->canAccess($student, $session) && $session->status === 'closed';
    }
}
