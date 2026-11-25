<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\ChatMemberStatusCache;
use App\Domain\Telegram\Services\DmReachability;
use App\Domain\Telegram\Services\GroupBotAccessChecker;
use App\Domain\Telegram\Services\GroupWelcomeMessenger;
use App\Domain\Telegram\Support\DmLearnerResolver;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
                'kicked' => $this->dmLearners->markBlocked($chatId, $this->updateDate($event)),
                'member' => $this->dmLearners->markReachable($chatId, DmReachability::VIA_MY_CHAT_MEMBER),
                default => null,
            };

            return;
        }

        if ($chatId === 0 || ! in_array($chatType, ['group', 'supergroup'], true)) {
            return;
        }

        $at = $this->updateDate($event) ?? CarbonImmutable::now()->utc()->startOfSecond();

        if (in_array($newStatus, ['left', 'kicked'], true)) {
            $stale = DB::transaction(function () use ($chatId, $newStatus, $at): bool {
                $group = $this->lockedGroup($chatId);
                if ($group === null) {
                    return false;
                }
                if ($this->isStale($group, $at)) {
                    return true;
                }

                // Through Eloquent (not a query-builder update): TelegramGroupObserver drops the
                // members' cached entitlement, so a sponsored Pro ends at once (docs/12 §5.2).
                $group->update(['status' => 'disabled', 'bot_status' => $newStatus, 'bot_status_at' => $at]);

                return false;
            });

            if ($stale) {
                $this->logStale($chatId, $newStatus);

                return;
            }

            $this->forgetBotStatus($event, $chatId);

            return;
        }

        $oldStatus = (string) ($event['old_chat_member']['status'] ?? '');

        /** @var array{0: TelegramGroup, 1: bool}|null $written the group and whether the bot was (re-)added */
        $written = DB::transaction(function () use ($chatId, $chat, $chatType, $newStatus, $oldStatus, $at): ?array {
            $group = $this->lockedGroup($chatId) ?? new TelegramGroup(['chat_id' => $chatId]);
            if ($group->exists && $this->isStale($group, $at)) {
                return null;
            }

            // New group, or the bot re-joined after leaving / being kicked.
            $botAdded = ! $group->exists || in_array($oldStatus, ['left', 'kicked'], true);

            if (! $group->exists) {
                $group->status = 'pending';
            }

            $group->title = (string) ($chat['title'] ?? __('bot.group.untitled', [], $this->locales->forGroup($group)));
            // Merge: admin-managed keys such as `locale` must survive a re-add.
            $group->meta = array_merge($group->meta ?? [], ['type' => $chatType]);
            $group->bot_status = $newStatus === '' ? null : mb_substr($newStatus, 0, 16);
            $group->bot_status_at = $at;
            $group->save();

            return [$group, $botAdded];
        });

        if ($written === null) {
            $this->logStale($chatId, $newStatus);

            return;
        }

        [$group, $botAdded] = $written;

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
     * The row of the group under `FOR UPDATE`: two `my_chat_member` updates of one chat handled at once (two workers) are put in
     * order by it, so the date check below sees the other one's write.
     */
    private function lockedGroup(int $chatId): ?TelegramGroup
    {
        return TelegramGroup::query()->where('chat_id', $chatId)->lockForUpdate()->first();
    }

    /**
     * docs/33 §2.1 (K2): an update older than the one the row already reflects changes nothing - Telegram may deliver them out of
     * order (a retried webhook, two workers). The same second is not older: two changes within a second apply in arrival order.
     */
    private function isStale(TelegramGroup $group, CarbonImmutable $at): bool
    {
        return $group->bot_status_at !== null && $at->lt($group->bot_status_at);
    }

    private function logStale(int $chatId, string $newStatus): void
    {
        Log::info('telegram.my_chat_member_stale', ['chat_id' => $chatId, 'status' => $newStatus]);
    }

    /**
     * `my_chat_member.date` (Unix time) of the update, or null when Telegram did not send one.
     *
     * @param  array<string, mixed>  $event
     */
    private function updateDate(array $event): ?CarbonImmutable
    {
        $date = $event['date'] ?? null;

        return is_int($date) && $date > 0 ? CarbonImmutable::createFromTimestampUTC($date) : null;
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
