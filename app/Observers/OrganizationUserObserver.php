<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Organizations\Services\OrgRequestMemo;
use App\Models\OrganizationUser;

/**
 * A staff row written or deleted changes the role the `/org` policies see (docs/13 §6.3): the per-request
 * memory of {@see OrgRequestMemo} is dropped at once, inside the transaction — the next check in the same
 * request reads the table (a rolled-back write is simply read back as it was). Registered in AppServiceProvider.
 * Query-builder writes (`OrganizationUser::query()->update()`) bypass observers: callers of those flush themselves.
 */
final class OrganizationUserObserver
{
    public function __construct(private readonly OrgRequestMemo $memo) {}

    public function saved(OrganizationUser $staff): void
    {
        $this->memo->flush();
    }

    public function deleted(OrganizationUser $staff): void
    {
        $this->memo->flush();
    }
}
