<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
     * Events can arrive late or out of order, so the state only moves forward in
     * time: a join dated before the `left_at` already recorded is ignored.
     *
     * @param  string  $via  where the join was observed (`group`, `chat_member`, ...); informational for callers
     * @param  CarbonInterface|null  $at  when it happened (the Telegram update `date`); defaults to now
     */
    public function join(Learner $learner, TelegramGroup $group, string $via, ?CarbonInterface $at = null): GroupMember
    {
        $at = $at !== null ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        try {
            return $this->upsertActive($learner, $group, $at);
        } catch (UniqueConstraintViolationException) {
            // Parallel join of the same pair (duplicate Telegram deliveries): the winner's row is fine.
            return $this->upsertActive($learner, $group, $at);
        }
    }

    /**
     * A leave dated before the `joined_at` already recorded (a stale, reordered
     * update) is ignored.
     *
     * @param  string  $status  `left` (the user left) or `removed` / `banned` (removed by someone else)
     * @param  CarbonInterface|null  $at  when it happened (the Telegram update `date`); defaults to now
     * @return bool whether an active membership was ended
     */
    public function leave(Learner $learner, TelegramGroup $group, string $status = self::LEFT, ?CarbonInterface $at = null): bool
    {
        $at = $at !== null ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        return GroupMember::query()
            ->where('telegram_group_id', $group->id)
            ->where('learner_id', $learner->id)
            ->where('status', self::ACTIVE)
            ->where('joined_at', '<=', $at)
            ->update(['status' => $status, 'left_at' => $at, 'updated_at' => now()]) > 0;
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

    private function upsertActive(Learner $learner, TelegramGroup $group, CarbonImmutable $at): GroupMember
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
                'joined_at' => $at,
            ]);

            return $created;
        }

        if (in_array($member->status, [self::LEFT, self::REMOVED], true)) {
            if ($member->left_at !== null && $member->left_at->greaterThan($at)) {
                return $member; // stale join: the newer leave stands
            }

            $member->update(['status' => self::ACTIVE, 'joined_at' => $at, 'left_at' => null]);
        }

        return $member;
    }
}
