<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Models\Student;
use App\Models\TelegramGroup;

final class NewMembersHandler implements UpdateHandler
{
    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $members = $update['message']['new_chat_members'] ?? null;

        return is_array($members) && $members !== [];
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

        foreach ($members as $m) {
            if (($m['is_bot'] ?? false) === true) {
                continue;
            }

            $tgId = isset($m['id']) ? (int) $m['id'] : 0;
            if ($tgId === 0) {
                continue;
            }

            Student::updateOrCreate(
                ['telegram_user_id' => $tgId, 'telegram_group_id' => $group->id],
                [
                    'first_name' => (string) ($m['first_name'] ?? 'Unknown'),
                    'last_name' => $m['last_name'] ?? null,
                    'username' => $m['username'] ?? null,
                    'language_code' => $m['language_code'] ?? null,
                    'last_seen_at' => now(),
                    'is_active' => true,
                ],
            );
        }
    }
}
