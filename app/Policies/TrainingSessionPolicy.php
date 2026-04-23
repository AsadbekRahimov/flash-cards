<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Student;
use App\Models\TrainingSession;

final class TrainingSessionPolicy
{
    /**
     * Can the student see this training session at all?
     * Valid iff student's group matches the session group and student is active.
     * (Session status is handled separately so we can return 410 for closed
     * sessions vs 403 for cross-group IDOR.)
     */
    public function canAccess(Student $student, TrainingSession $session): bool
    {
        return $student->is_active
            && (int) $student->telegram_group_id === (int) $session->telegram_group_id;
    }

    /**
     * Can the student actively participate (review) right now?
     * Requires session to be open on top of the access check.
     */
    public function canParticipate(Student $student, TrainingSession $session): bool
    {
        return $this->canAccess($student, $session) && $session->status === 'open';
    }
}
