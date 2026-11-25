<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Polls\Enums\PollStatus;
use App\Models\Poll;
use App\Models\User;

/**
 * Polls in the bot (docs/35 §1.5): made, sent, closed and read by an active platform admin only - the answers are personal data
 * (who answered what). Not in `/org`. Only a draft is deleted: a launched poll is history (its totals, its audit records).
 */
final class PollPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, Poll $poll): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Poll $poll): bool
    {
        return $this->manage($user);
    }

    /** The test send, the launch, the stop and the closing (each also refused by its service when the status does not fit). */
    public function run(User $user, Poll $poll): bool
    {
        return $this->manage($user);
    }

    /** The results page and its CSV files (the answers by person). */
    public function results(User $user, Poll $poll): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, Poll $poll): bool
    {
        return $this->manage($user) && $poll->status === PollStatus::Draft;
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
