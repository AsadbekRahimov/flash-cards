<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LeagueMonth;
use App\Models\User;

/**
 * The monthly rating with prizes (docs/13 §7.6): the review page and every decision on it — confirm, disqualify,
 * reinstate, "prize delivered", the prizes — are for an active platform admin only (as the other platform actions:
 * promo codes, payments, blocking a learner). Teachers do not see it; months are never created or deleted by hand.
 */
final class LeagueMonthPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, LeagueMonth $month): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    /** Every decision of the review (MonthlyRatingReviewService) is an update of the month. */
    public function update(User $user, LeagueMonth $month): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, LeagueMonth $month): bool
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
