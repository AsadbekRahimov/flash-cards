<?php

declare(strict_types=1);

namespace App\Policies\Org;

use App\Domain\Game\Services\GameAccess;
use App\Domain\Organizations\Services\OrgTenantScope;
use App\Models\ClassCaravan;
use App\Models\User;
use App\Policies\Org\Concerns\ResolvesOrgTenant;

/**
 * Class caravans in the `/org` cabinet (docs/18 §12.10, G2.9b): the owner sees the caravans of every group of the center, a teacher those
 * of their own groups ({@see OrgTenantScope::classCaravans()}). Starting and canceling are the rule of
 * `ClassCaravanService`: only in a group the user teaches (`teacher_groups`) - an owner who is not assigned to a group looks, and does not
 * run it. The whole page is closed while the game is (`GAME_ACCESS=off`). Nothing is edited or deleted: a cancel is a custom action.
 */
final class OrgClassCaravanPolicy
{
    use ResolvesOrgTenant;

    public function viewAny(User $user): bool
    {
        return $this->open() && $this->staffTenant($user) !== null;
    }

    public function view(User $user, ClassCaravan $caravan): bool
    {
        $tenant = $this->staffTenant($user);

        return $this->open() && $tenant !== null && $this->scope()->canSeeClassCaravan($user, $tenant, $caravan);
    }

    /** Start: at least one group of the center the user teaches. */
    public function create(User $user): bool
    {
        $tenant = $this->staffTenant($user);

        return $this->open() && $tenant !== null && $this->scope()->caravanGroupIds($user, $tenant) !== [];
    }

    /** Cancel: a caravan on its way in a group the user teaches. */
    public function cancel(User $user, ClassCaravan $caravan): bool
    {
        $tenant = $this->staffTenant($user);

        return $this->open()
            && $tenant !== null
            && $caravan->isActive()
            && $this->scope()->canSeeClassCaravan($user, $tenant, $caravan)
            && in_array($caravan->telegram_group_id, $this->scope()->caravanGroupIds($user, $tenant), true);
    }

    public function update(User $user, ClassCaravan $caravan): bool
    {
        return false;
    }

    public function delete(User $user, ClassCaravan $caravan): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function open(): bool
    {
        return app(GameAccess::class)->classCaravanOpen();
    }
}
