<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Models\Assignment;
use Carbon\CarbonImmutable;

/**
 * Closes the active assignments a center set for a group that no longer belongs to it
 * (unlinked, or moved to another center). Called from `TelegramGroupObserver` inside the
 * transaction that changes `telegram_groups.organization_id`, so every path (the owner's unlink,
 * the superadmin's, a direct save) is covered. No Telegram call, no recount: the snapshot stays as
 * it was (docs/13 "Реализация 2B.8"). The queries of OrgTenantScope / AssignmentService
 * additionally require the group to still belong to the assignment's center.
 */
final class GroupAssignmentCloser
{
    public const REASON = 'group_unlinked';

    /** @return int how many assignments were closed */
    public function closeForGroup(int $groupId, int $organizationId, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $closed = 0;

        Assignment::query()
            ->where('telegram_group_id', $groupId)
            ->where('organization_id', $organizationId)
            ->where('status', Assignment::STATUS_ACTIVE)
            ->orderBy('id')
            ->each(function (Assignment $assignment) use ($now, &$closed): void {
                $meta = $assignment->meta;
                $meta['closed_reason'] = self::REASON;

                $closed += Assignment::query()
                    ->whereKey($assignment->id)
                    ->where('status', Assignment::STATUS_ACTIVE)
                    ->update(['status' => Assignment::STATUS_CLOSED, 'meta' => json_encode($meta, JSON_THROW_ON_ERROR), 'updated_at' => $now]);
            });

        return $closed;
    }
}
