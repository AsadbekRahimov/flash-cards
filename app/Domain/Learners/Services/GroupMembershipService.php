<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\TelegramGroup;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * Membership of a learner in a Telegram group (`group_members`, docs/12 §1.3).
 *
 * "Active membership" = `group_members.status = 'active'` AND the group itself
 * is `active`; this is the only rule behind every group-scoped access check.
 */
final class GroupMembershipService
{
    public const ACTIVE = 'active';

    public const LEFT = 'left';

    public const REMOVED = 'removed';

    public const BANNED = 'banned';

    /**
     * Idempotent: creates the membership or re-activates a `left` / `removed` one.
     * A `banned` membership stays banned (it is lifted only by an admin).
     *
     * @param  string  $via  where the join was observed (`group`, `chat_member`, ...); informational for callers
     */
    public function join(Learner $learner, TelegramGroup $group, string $via): GroupMember
    {
        try {
            return $this->upsertActive($learner, $group);
        } catch (UniqueConstraintViolationException) {
            // Parallel join of the same pair (duplicate Telegram deliveries): the winner's row is fine.
            return $this->upsertActive($learner, $group);
        }
    }

    /**
     * @param  string  $status  `left` (the user left) or `removed` / `banned` (removed by someone else)
     * @return bool whether an active membership was ended
     */
    public function leave(Learner $learner, TelegramGroup $group, string $status = self::LEFT): bool
    {
        return GroupMember::query()
            ->where('telegram_group_id', $group->id)
            ->where('learner_id', $learner->id)
            ->where('status', self::ACTIVE)
            ->update(['status' => $status, 'left_at' => now(), 'updated_at' => now()]) > 0;
    }

    public function isActiveMember(Learner $learner, TelegramGroup $group): bool
    {
        return $group->status === 'active'
            && GroupMember::query()
                ->where('telegram_group_id', $group->id)
                ->where('learner_id', $learner->id)
                ->where('status', self::ACTIVE)
                ->exists();
    }

    /**
     * Why $learner may not act inside $group, or null when they may:
     * `group_inactive` — an active member of a group that is not (yet) enabled;
     * `not_a_member`   — anything else (no row, left, removed, banned), so the
     *                    existence of foreign groups is not disclosed.
     */
    public function denialReason(Learner $learner, ?TelegramGroup $group): ?string
    {
        if ($group === null) {
            return 'not_a_member';
        }

        $hasActiveRow = GroupMember::query()
            ->where('telegram_group_id', $group->id)
            ->where('learner_id', $learner->id)
            ->where('status', self::ACTIVE)
            ->exists();

        if (! $hasActiveRow) {
            return 'not_a_member';
        }

        return $group->status === 'active' ? null : 'group_inactive';
    }

    /** @return list<int> */
    public function activeGroupIds(Learner $learner): array
    {
        return array_values(
            $this->activeGroups($learner)
                ->map(fn (TelegramGroup $group): int => (int) $group->id)
                ->all(),
        );
    }

    /** @return Collection<int, TelegramGroup> */
    public function activeGroups(Learner $learner): Collection
    {
        return TelegramGroup::query()
            ->where('status', 'active')
            ->whereHas(
                'members',
                fn ($q) => $q->where('learner_id', $learner->id)->where('status', self::ACTIVE),
            )
            ->orderBy('id')
            ->get();
    }

    private function upsertActive(Learner $learner, TelegramGroup $group): GroupMember
    {
        /** @var GroupMember|null $member */
        $member = GroupMember::query()
            ->where('telegram_group_id', $group->id)
            ->where('learner_id', $learner->id)
            ->first();

        if ($member === null) {
            /** @var GroupMember $created */
            $created = GroupMember::query()->create([
                'telegram_group_id' => $group->id,
                'learner_id' => $learner->id,
                'status' => self::ACTIVE,
                'joined_at' => now(),
            ]);

            return $created;
        }

        if (in_array($member->status, [self::LEFT, self::REMOVED], true)) {
            $member->update(['status' => self::ACTIVE, 'joined_at' => now(), 'left_at' => null]);
        }

        return $member;
    }
}
