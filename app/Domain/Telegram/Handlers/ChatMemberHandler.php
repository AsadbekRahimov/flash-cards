<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learners\Services\GroupMembershipService;
use App\Domain\Learners\Services\LearnerRegistrar;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Models\Learner;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;

/**
 * `chat_member` updates: a person's own membership in a group changed (the bot
 * must be an admin of the group to receive them). Keeps `group_members` in sync:
 *
 *   member / administrator / creator / restricted(is_member = true) -> join (re-activates left / removed)
 *   left / restricted(is_member != true)                            -> status `left`
 *   kicked                                                          -> status `removed`
 *
 * Both directions are passive events: a soft-deleted learner is neither revived
 * nor given a membership, and `last_seen_at` is not touched. Updates are applied
 * by their Telegram `date`, so a late one cannot undo a newer state.
 *
 * Changes of the bot's own membership are `my_chat_member` (MyChatMemberHandler).
 */
final class ChatMemberHandler implements UpdateHandler
{
    private const JOINED_STATUSES = ['member', 'administrator', 'creator'];

    public function __construct(
        private readonly ProductEventRecorder $events,
        private readonly LearnerRegistrar $registrar,
        private readonly GroupMembershipService $memberships,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        return isset($update['chat_member']) && is_array($update['chat_member']);
    }

    // Lifecycle handler (like new members): membership is tracked for every known group.
    public function requiresActiveGroup(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $event */
        $event = $update['chat_member'];
        /** @var array<string, mixed> $chat */
        $chat = $event['chat'] ?? [];
        $chatId = isset($chat['id']) ? (int) $chat['id'] : 0;

        if ($chatId === 0 || ! in_array((string) ($chat['type'] ?? ''), ['group', 'supergroup'], true)) {
            return;
        }

        $group = TelegramGroup::query()->where('chat_id', $chatId)->first();
        if ($group === null) {
            return;
        }

        /** @var array<string, mixed> $newMember */
        $newMember = is_array($event['new_chat_member'] ?? null) ? $event['new_chat_member'] : [];
        /** @var array<string, mixed> $user */
        $user = is_array($newMember['user'] ?? null) ? $newMember['user'] : [];

        if (($user['is_bot'] ?? false) === true || ! isset($user['id'])) {
            return;
        }

        $status = (string) ($newMember['status'] ?? '');
        $at = isset($event['date']) && is_numeric($event['date']) ? CarbonImmutable::createFromTimestamp((int) $event['date']) : null;

        // A muted participant who left is reported as `restricted` with is_member = false.
        $restrictedOut = $status === 'restricted' && ($newMember['is_member'] ?? false) !== true;

        if (in_array($status, ['left', 'kicked'], true) || $restrictedOut) {
            $this->leave((int) $user['id'], $group, $status === 'kicked' ? GroupMembershipService::REMOVED : GroupMembershipService::LEFT, $at);

            return;
        }

        $isMember = in_array($status, self::JOINED_STATUSES, true) || $status === 'restricted';
        if (! $isMember) {
            return;
        }

        $learner = $this->registrar->observeTelegramUser($user, 'group');
        if ($learner === null) {
            return;
        }

        $member = $this->memberships->join($learner, $group, 'chat_member', $at);

        if ($member->wasRecentlyCreated || $member->wasChanged('status')) {
            $this->events->record(ProductEventName::GroupMemberJoined, ['via' => 'chat_member'], learner: $learner, group: $group);
        }
    }

    private function leave(int $telegramUserId, TelegramGroup $group, string $status, ?CarbonImmutable $at): void
    {
        // Never create a learner just to record that they left.
        /** @var Learner|null $learner */
        $learner = Learner::query()->where('telegram_user_id', $telegramUserId)->first();
        if ($learner === null) {
            return;
        }

        if ($this->memberships->leave($learner, $group, $status, $at)) {
            $this->events->record(ProductEventName::GroupMemberLeft, ['reason' => $status], learner: $learner, group: $group);
        }
    }
}
