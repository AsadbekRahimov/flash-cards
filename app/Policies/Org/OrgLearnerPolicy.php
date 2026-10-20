<?php

declare(strict_types=1);

namespace App\Policies\Org;

use App\Models\Learner;
use App\Models\User;
use App\Policies\Org\Concerns\ResolvesOrgTenant;

/**
 * Learners inside a cabinet: only those with an ACTIVE membership in the tenant (owner), or
 * additionally active in one of the teacher's groups (teacher); read-only — a center never edits
 * a learner's account (docs/12 §4.4 privacy: aggregates only, 2B.5).
 */
final class OrgLearnerPolicy
{
    use ResolvesOrgTenant;

    public function viewAny(User $user): bool
    {
        return $this->staffTenant($user) !== null;
    }

    public function view(User $user, Learner $learner): bool
    {
        $tenant = $this->staffTenant($user);

        return $tenant !== null && $this->scope()->canSeeLearner($user, $tenant, $learner);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Learner $learner): bool
    {
        return false;
    }

    public function delete(User $user, Learner $learner): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
