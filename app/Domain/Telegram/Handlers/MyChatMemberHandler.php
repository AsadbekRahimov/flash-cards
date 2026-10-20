<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\GroupBotAccessChecker;
use App\Domain\Telegram\Support\DmLearnerResolver;
use App\Models\TelegramGroup;

final class MyChatMemberHandler implements UpdateHandler
{
    public function __construct(
        private readonly ProductEventRecorder $events,
        private readonly DmLearnerResolver $dmLearners,
        private readonly GroupBotAccessChecker $botAccess,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        return isset($update['my_chat_member']);
    }

    // Lifecycle handler: this is how groups become known/activated, so it must
    // run regardless of the whitelist.
    public function requiresActiveGroup(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $event */
        $event = $update['my_chat_member'];
        /** @var array<string, mixed> $chat */
        $chat = $event['chat'] ?? [];
        $chatId = isset($chat['id']) ? (int) $chat['id'] : 0;
        $chatType = (string) ($chat['type'] ?? '');

        $newStatus = (string) ($event['new_chat_member']['status'] ?? '');

        // The user blocked / unblocked the bot: in a private chat `chat.id` is the user's id.
        // No learner is created — this is not the person writing to the bot (docs/12 §7.5).
        if ($chatType === 'private') {
            match ($newStatus) {
                'kicked' => $this->dmLearners->markBlocked($chatId),
                'member' => $this->dmLearners->markReachable($chatId),
                default => null,
            };

            return;
        }

        if ($chatId === 0 || ! in_array($chatType, ['group', 'supergroup'], true)) {
            return;
        }

        if (in_array($newStatus, ['left', 'kicked'], true)) {
            // Through Eloquent (not a query-builder update): TelegramGroupObserver drops the
            // members' cached entitlement, so a sponsored Pro ends at once (docs/12 §5.2).
            TelegramGroup::query()->where('chat_id', $chatId)->first()?->update(['status' => 'disabled']);

            return;
        }

        $group = TelegramGroup::firstOrNew(['chat_id' => $chatId]);

        // New group, or the bot re-joined after leaving / being kicked.
        $oldStatus = (string) ($event['old_chat_member']['status'] ?? '');
        $botAdded = ! $group->exists || in_array($oldStatus, ['left', 'kicked'], true);

        if (! $group->exists) {
            $group->status = 'pending';
        }

        $group->title = (string) ($chat['title'] ?? 'Untitled group');
        // Merge: admin-managed keys such as `locale` must survive a re-add.
        $group->meta = array_merge($group->meta ?? [], ['type' => $chatType]);
        $group->save();

        if ($botAdded) {
            $this->events->record(
                ProductEventName::GroupBotAdded,
                ['chat_type' => $chatType, 'readded' => ! $group->wasRecentlyCreated],
                group: $group,
            );

            $botId = $event['new_chat_member']['user']['id'] ?? null;
            $this->botAccess->warnIfNotAdmin($group, is_int($botId) ? $botId : null);
        }
    }
}
