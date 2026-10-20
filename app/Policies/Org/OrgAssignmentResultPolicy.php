<?php

declare(strict_types=1);

namespace App\Policies\Org;

use App\Models\AssignmentResult;
use App\Models\User;
use App\Policies\Org\Concerns\ResolvesOrgTenant;

/**
 * Per-learner progress rows inside an assignment report: read-only, visible exactly when the
 * assignment is (owner — all, teacher — own groups). Only the aggregate progress is stored, so
 * nothing personal can leak (docs/12 §4.4).
 */
final class OrgAssignmentResultPolicy
{
    use ResolvesOrgTenant;

    public function viewAny(User $user): bool
    {
        return $this->staffTenant($user) !== null;
    }

    public function view(User $user, AssignmentResult $result): bool
    {
        $tenant = $this->staffTenant($user);

        return $tenant !== null && $this->scope()->canSeeAssignment($user, $tenant, $result->assignment);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AssignmentResult $result): bool
    {
        return false;
    }

    public function delete(User $user, AssignmentResult $result): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
