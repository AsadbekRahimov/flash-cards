<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learners\Services\GroupMembershipService;
use App\Domain\Learners\Services\LearnerRegistrar;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;

final class NewMembersHandler implements UpdateHandler
{
    public function __construct(
        private readonly ProductEventRecorder $events,
        private readonly LearnerRegistrar $registrar,
        private readonly GroupMembershipService $memberships,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $members = $update['message']['new_chat_members'] ?? null;

        return is_array($members) && $members !== [];
    }

    // Lifecycle handler: performs its own group resolution and must keep
    // working independently of the command whitelist.
    public function requiresActiveGroup(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $message */
        $message = $update['message'];
        $chatId = (int) $message['chat']['id'];

        $group = TelegramGroup::where('chat_id', $chatId)->first();
        if ($group === null) {
            return;
        }

        /** @var list<array<string, mixed>> $members */
        $members = $message['new_chat_members'];
        $at = isset($message['date']) && is_numeric($message['date']) ? CarbonImmutable::createFromTimestamp((int) $message['date']) : null;

        foreach ($members as $m) {
            if (($m['is_bot'] ?? false) === true) {
                continue;
            }

            $tgId = isset($m['id']) ? (int) $m['id'] : 0;
            if ($tgId === 0) {
                continue;
            }

            // First contact through a group: no /start in DM yet (acquisition_source 'group').
            // A passive event: a soft-deleted learner stays deleted and gets no membership.
            $learner = $this->registrar->observeTelegramUser($m, 'group');
            if ($learner === null) {
                continue;
            }

            $member = $this->memberships->join($learner, $group, 'group', $at);

            if ($member->wasRecentlyCreated || $member->wasChanged('status')) {
                $this->events->record(
                    ProductEventName::GroupMemberJoined,
                    ['via' => 'group'],
                    learner: $learner,
                    group: $group,
                );
            }
        }
    }
}
