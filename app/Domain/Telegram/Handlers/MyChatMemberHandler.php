<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Models\TelegramGroup;

final class MyChatMemberHandler implements UpdateHandler
{
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

        if ($chatId === 0 || ! in_array($chatType, ['group', 'supergroup'], true)) {
            return;
        }

        $newStatus = (string) ($event['new_chat_member']['status'] ?? '');

        if (in_array($newStatus, ['left', 'kicked'], true)) {
            TelegramGroup::where('chat_id', $chatId)->update(['status' => 'disabled']);

            return;
        }

        $group = TelegramGroup::firstOrNew(['chat_id' => $chatId]);

        if (! $group->exists) {
            $group->status = 'pending';
        }

        $group->title = (string) ($chat['title'] ?? 'Untitled group');
        $group->meta = ['type' => $chatType];
        $group->save();
    }
}
