<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Learners\Services\GroupMembershipService;
use App\Models\Learner;
use App\Models\TrainingSession;

final class TrainingSessionPolicy
{
    public function __construct(private readonly GroupMembershipService $memberships) {}

    /**
     * Error code to answer with (403) when the learner may not see this session,
     * null when access is granted:
     *   - personal session (`mode` <> group_lesson): only its owner (`forbidden`);
     *   - group session: an active member of an active group (`not_a_member` / `group_inactive`).
     * Session status is handled separately so closed sessions answer 410, not 403.
     */
    public function denial(Learner $learner, TrainingSession $session): ?string
    {
        if ($learner->blocked_at !== null) {
            return 'forbidden';
        }

        if ($session->mode !== 'group_lesson') {
            return (int) $session->learner_id === (int) $learner->id ? null : 'forbidden';
        }

        return $this->memberships->denialReason($learner, $session->group);
    }

    public function canAccess(Learner $learner, TrainingSession $session): bool
    {
        return $this->denial($learner, $session) === null;
    }

    /**
     * Can the learner actively participate (review) right now?
     * Requires the session to be open on top of the access check.
     */
    public function canParticipate(Learner $learner, TrainingSession $session): bool
    {
        return $this->canAccess($learner, $session) && $session->status === 'open';
    }
}
