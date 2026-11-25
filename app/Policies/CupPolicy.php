<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Cup\Services\CupPlatformAdmin;
use App\Models\Cup;
use App\Models\User;

/**
 * The cups of the groups in `/admin` (docs/33 §8, K9): seen and acted on by an active platform admin only - a teacher of the panel sees
 * nothing of them (the names in a tournament are personal data of the chat). Read-only as a resource; the cancel, the reset of a name
 * and the flags of a group are custom actions, each authorized here and again by {@see CupPlatformAdmin}.
 */
final class CupPolicy
{
    public function viewAny(User $user): bool
    {
        return CupPlatformAdmin::isAdmin($user);
    }

    public function view(User $user, Cup $cup): bool
    {
        return CupPlatformAdmin::isAdmin($user);
    }

    /** "Отменить кубок": an open cup (draft, scheduled, running). */
    public function cancel(User $user, Cup $cup): bool
    {
        return CupPlatformAdmin::isAdmin($user) && $cup->status->isOpen();
    }

    /** "Сбросить имя" of a participant and the flags of a group ("Запретить кубки", "Кубок: пилот"). */
    public function manage(User $user): bool
    {
        return CupPlatformAdmin::isAdmin($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Cup $cup): bool
    {
        return false;
    }

    public function delete(User $user, Cup $cup): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
