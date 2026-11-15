<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\ChatMemberStatusCache;
use App\Domain\Telegram\Services\GroupBotAccessChecker;
use App\Domain\Telegram\Services\GroupWelcomeMessenger;
use App\Domain\Telegram\Support\DmLearnerResolver;
use App\Models\TelegramGroup;

final class MyChatMemberHandler implements UpdateHandler
{
    public function __construct(
        private readonly ProductEventRecorder $events,
        private readonly DmLearnerResolver $dmLearners,
        private readonly GroupBotAccessChecker $botAccess,
        private readonly ChatMemberStatusCache $members,
        private readonly GroupWelcomeMessenger $welcome,
        private readonly LocaleResolver $locales,
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
            $this->forgetBotStatus($event, $chatId);

            return;
        }

        $group = TelegramGroup::firstOrNew(['chat_id' => $chatId]);

        // New group, or the bot re-joined after leaving / being kicked.
        $oldStatus = (string) ($event['old_chat_member']['status'] ?? '');
        $botAdded = ! $group->exists || in_array($oldStatus, ['left', 'kicked'], true);

        if (! $group->exists) {
            $group->status = 'pending';
        }

        $group->title = (string) ($chat['title'] ?? __('bot.group.untitled', [], $this->locales->forGroup($group)));
        // Merge: admin-managed keys such as `locale` must survive a re-add.
        $group->meta = array_merge($group->meta ?? [], ['type' => $chatType]);
        $group->save();

        // After the group is written, before the check below reads the status again.
        $this->forgetBotStatus($event, $chatId);

        if ($botAdded) {
            $this->events->record(
                ProductEventName::GroupBotAdded,
                ['chat_type' => $chatType, 'readded' => ! $group->wasRecentlyCreated],
                group: $group,
            );

            $botId = $event['new_chat_member']['user']['id'] ?? null;
            $botId = is_int($botId) ? $botId : null;

            // A group that waits for activation gets ONE message: what to do next (+ the admin warning inside it).
            if ($group->status === 'pending') {
                $this->welcome->send($group, $botId);
            } else {
                $this->botAccess->warnIfNotAdmin($group, $botId);
            }
        }
    }

    /**
     * The bot's status in this group changed: whatever was remembered of it (docs/19 K7) is stale from now on, whether the bot
     * was promoted, demoted, removed or added; the next check asks Telegram. A cache that cannot be reached is reported and
     * nothing else: the update (the group going `disabled`, the registration of a group) is business logic and must never fail
     * because the pure cache is down - at worst the remembered status lives out its ten minutes.
     *
     * @param  array<string, mixed>  $event
     */
    private function forgetBotStatus(array $event, int $chatId): void
    {
        $updatedBotId = $event['new_chat_member']['user']['id'] ?? null;
        $botId = is_int($updatedBotId) ? $updatedBotId : $this->members->botId();

        if ($botId !== null) {
            rescue(fn () => $this->members->forget($chatId, $botId));
        }
    }
}
