<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Organizations\Services\OrganizationMembershipService;
use App\Domain\Telegram\Handlers\ChatMigrationHandler;
use App\Models\TelegramGroup;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A Telegram group became a supergroup and got a new chat id (docs/33 §4.6): the `telegram_groups` row follows it, so the
 * organization, teachers, members, sessions and caravans of the group keep working. Everything else references the row by
 * `telegram_group_id` and needs no change.
 *
 * Three ways in, all idempotent and safe to run at the same time:
 *  - `message.migrate_to_chat_id` in the old chat and `message.migrate_from_chat_id` in the new one ({@see ChatMigrationHandler});
 *  - a send to the old chat refused with `parameters.migrate_to_chat_id` ({@see IrazasyedTelegramClient}).
 * The first caller moves the row, the others find it moved and do nothing.
 *
 * Under one transaction both candidate rows are locked (`FOR UPDATE`, in id order, so two callers never wait for each other
 * crosswise). Then:
 *  - no row of the old chat → nothing to move (already moved, or a group we never knew): the row of the new chat, if any;
 *  - only the old row → its `chat_id` becomes the new one;
 *  - both rows: the bot's `my_chat_member` in the new supergroup came first and `MyChatMemberHandler` created a row for it.
 *    That row is seconds old: the old row (organization, teachers, history) survives, the new one's members and events move over
 *    and it is deleted. If the new row has a life of its own (activated, linked to a center, has teachers / sessions / caravans /
 *    assignments / invites) nothing is merged automatically: `telegram.group_migration_conflict` is logged for an admin and the
 *    new row is returned. Sends go to the new chat anyway.
 *
 * A third party may insert the new chat id between our lock and our UPDATE (`MyChatMemberHandler::firstOrNew` does not lock):
 * the unique index refuses one of the two; we run the whole step once more and then see both rows.
 */
final class GroupChatMigrator
{
    /** Tables whose rows of a group are its own history: a group row that has any of them is never merged away automatically. */
    private const HISTORY_TABLES = [
        'teacher_groups',
        'training_sessions',
        'exam_sessions',
        'class_caravans',
        'assignments',
        'organization_invites',
    ];

    public function __construct(
        private readonly OrganizationMembershipService $organizations,
        private readonly EntitlementResolver $entitlements,
    ) {}

    /** @return TelegramGroup|null the row that now carries `$toChatId`, or null when we know neither chat */
    public function migrate(int $fromChatId, int $toChatId): ?TelegramGroup
    {
        if ($fromChatId === 0 || $toChatId === 0 || $fromChatId === $toChatId) {
            return null;
        }

        try {
            return DB::transaction(fn (): ?TelegramGroup => $this->migrateLocked($fromChatId, $toChatId));
        } catch (UniqueConstraintViolationException) {
            // A row of the new chat was inserted next to ours in the meantime: now both are visible, merge.
            return DB::transaction(fn (): ?TelegramGroup => $this->migrateLocked($fromChatId, $toChatId));
        }
    }

    private function migrateLocked(int $fromChatId, int $toChatId): ?TelegramGroup
    {
        $rows = TelegramGroup::query()
            ->whereIn('chat_id', [$fromChatId, $toChatId])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $old = $rows->first(fn (TelegramGroup $group): bool => $group->chat_id === $fromChatId);
        $new = $rows->first(fn (TelegramGroup $group): bool => $group->chat_id === $toChatId);

        if ($old === null) {
            return $new;
        }

        if ($new !== null) {
            if ($this->hasHistory($new)) {
                Log::error('telegram.group_migration_conflict', [
                    'old_group_id' => $old->id,
                    'new_group_id' => $new->id,
                ]);

                return $new;
            }

            $this->mergeInto($new, $old);
        }

        // Through Eloquent: the observer sees a save (no status / organization change: nothing to do for it today).
        $old->update([
            'chat_id' => $toChatId,
            'meta' => array_merge($old->meta ?? [], ['type' => 'supergroup', 'migrated_from_chat_id' => $fromChatId]),
        ]);

        Log::info('telegram.group_migrated', ['telegram_group_id' => $old->id, 'merged' => $new !== null]);

        return $old;
    }

    private function hasHistory(TelegramGroup $group): bool
    {
        if ($group->status === 'active' || $group->organization_id !== null) {
            return true;
        }

        foreach (self::HISTORY_TABLES as $table) {
            if (DB::table($table)->where('telegram_group_id', $group->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Members and product events of the fresh row move to the surviving one; a learner who is in both keeps the survivor's
     * membership. Then the fresh row is deleted through Eloquent (its observer drops the pending-groups badge).
     */
    private function mergeInto(TelegramGroup $from, TelegramGroup $into): void
    {
        DB::table('group_members')
            ->where('telegram_group_id', $from->id)
            ->whereIn('learner_id', DB::table('group_members')->where('telegram_group_id', $into->id)->select('learner_id'))
            ->delete();

        $moved = DB::table('group_members')
            ->where('telegram_group_id', $from->id)
            ->update(['telegram_group_id' => $into->id, 'updated_at' => now()]);

        DB::table('product_events')
            ->where('telegram_group_id', $from->id)
            ->update(['telegram_group_id' => $into->id]);

        $from->delete();

        if ($moved > 0) {
            // Moved rows bypass GroupMembershipService::join: the center membership and the cached plans follow here.
            DB::afterCommit(function () use ($into): void {
                $this->guard('telegram.group_migration_sync_failed', $into, function () use ($into): void {
                    $fresh = $into->fresh();
                    if ($fresh !== null) {
                        $this->organizations->syncGroup($fresh);
                    }
                });
                $this->guard('telegram.group_migration_entitlements_failed', $into, fn () => $this->entitlements->forgetGroup($into->id));
            });
        }
    }

    /** @param callable(): mixed $hook */
    private function guard(string $failureLog, TelegramGroup $group, callable $hook): void
    {
        try {
            $hook();
        } catch (Throwable $e) {
            Log::warning($failureLog, ['telegram_group_id' => $group->id, 'exception' => $e::class]);
        }
    }
}
