<?php

declare(strict_types=1);

namespace App\Policies\Org;

use App\Domain\Organizations\Services\OrgTenantScope;
use App\Models\ExamSession;
use App\Models\User;
use App\Policies\Org\Concerns\ResolvesOrgTenant;

/**
 * Exams in the `/org` cabinet: read-only. The owner sees the exams of every group of the center, a teacher those of their own groups
 * ({@see OrgTenantScope::exams()}); nothing is created, edited or deleted here - an exam is run from the group chat.
 */
final class OrgExamSessionPolicy
{
    use ResolvesOrgTenant;

    public function viewAny(User $user): bool
    {
        return $this->staffTenant($user) !== null;
    }

    public function view(User $user, ExamSession $exam): bool
    {
        $tenant = $this->staffTenant($user);

        return $tenant !== null && $this->scope()->canSeeExam($user, $tenant, $exam);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ExamSession $exam): bool
    {
        return false;
    }

    public function delete(User $user, ExamSession $exam): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
