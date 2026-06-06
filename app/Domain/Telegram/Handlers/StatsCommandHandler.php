<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\TeacherStatsReporter;
use App\Models\User;

final class StatsCommandHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly TeacherStatsReporter $reporter,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $message = $update['message'] ?? null;
        if (! is_array($message)) {
            return false;
        }

        $chatType = (string) ($message['chat']['type'] ?? '');
        $text = (string) ($message['text'] ?? '');

        return $chatType === 'private'
            && preg_match('~^/stats(?:@\w+)?(\s|$)~', $text) === 1;
    }

    // Private-chat only command; the Group Lock does not apply.
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
        $fromId = (int) ($message['from']['id'] ?? 0);

        if ($fromId === 0) {
            return;
        }

        /** @var User|null $teacher */
        $teacher = User::query()
            ->where('telegram_user_id', $fromId)
            ->where('is_active', true)
            ->first();

        if ($teacher === null) {
            $this->telegram->sendMessage(
                $chatId,
                "Этот Telegram-аккаунт не привязан к учителю LexiFlow.\nВаш Telegram ID: {$fromId}",
            );

            return;
        }

        $this->telegram->sendMessage($chatId, $this->reporter->build($teacher));
    }
}
