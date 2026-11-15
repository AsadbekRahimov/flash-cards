<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Domain\Administration\Services\OrganizationGroupLinker;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one place that writes `teacher_groups` for the staff of a center: {@see OrganizationStaffService::syncGroups()},
 * {@see OrgGroupLinkService} (the owner who links a group teaches it) and the unlink of a group
 * ({@see OrganizationGroupLinker}). Call it inside a transaction.
 */
final class TeacherGroupAssignments
{
    public function __construct(private readonly OrgRequestMemo $memo) {}

    /**
     * Gives the user the groups that are not theirs yet. The first group a teacher ever gets is their primary one
     * (Phase-1 semantics of the column): the user row is locked first, so two parallel attachments cannot both see
     * "no groups yet" and write two primaries.
     *
     * @param  list<int>  $groupIds
     */
    public function attach(int $userId, array $groupIds): void
    {
        if ($groupIds === []) {
            return;
        }

        User::query()->whereKey($userId)->lockForUpdate()->first();

        $have = DB::table('teacher_groups')->where('user_id', $userId)->pluck('telegram_group_id')->map(fn ($id): int => (int) $id)->all();
        $new = array_values(array_diff($groupIds, $have));
        if ($new === []) {
            return;
        }

        $hasAny = $have !== [];
        $now = now();

        DB::table('teacher_groups')->insert(array_map(fn (int $groupId, int $i): array => [
            'user_id' => $userId,
            'telegram_group_id' => $groupId,
            'is_primary' => ! $hasAny && $i === 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $new, array_keys($new)));

        // Written with the query builder (no model events): the per-request memory of the cabinet is dropped by hand.
        $this->memo->flush();
    }

    /**
     * Takes the groups away from the user. A primary mark that goes with its row is not handed on: nothing reads it
     * (as in {@see OrganizationStaffService::syncGroups()}).
     *
     * @param  list<int>  $groupIds
     */
    public function detach(int $userId, array $groupIds): void
    {
        if ($groupIds === []) {
            return;
        }

        DB::table('teacher_groups')->where('user_id', $userId)->whereIn('telegram_group_id', $groupIds)->delete();
        $this->memo->flush();
    }

    /**
     * A group left the center: its teachers among the center's staff stop teaching it (the owner who linked it
     * included). Teachers of other centers and users who are not staff of this one are left alone.
     *
     * @return int rows removed
     */
    public function detachStaffFromGroup(int $organizationId, int $groupId): int
    {
        $removed = DB::table('teacher_groups')
            ->where('telegram_group_id', $groupId)
            ->whereIn('user_id', DB::table('organization_users')->where('organization_id', $organizationId)->select('user_id'))
            ->delete();

        $this->memo->flush();

        return $removed;
    }
}
