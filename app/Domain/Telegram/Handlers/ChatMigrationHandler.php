<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\GroupChatMigrator;

/**
 * The service messages of a group that became a supergroup (docs/33 §4.6, core.telegram.org/bots/api#message): the old chat
 * gets `migrate_to_chat_id`, the new one `migrate_from_chat_id`. Bots receive all service messages, privacy mode or not. Whichever
 * comes first moves the group row ({@see GroupChatMigrator}); the second one finds it moved. Lifecycle update: runs whatever the
 * group's status, first among the message handlers, and is never dropped by the inbound flood limit.
 */
final class ChatMigrationHandler implements UpdateHandler
{
    public function __construct(private readonly GroupChatMigrator $migrator) {}

    /** @param array<string, mixed> $update */
    public static function isMigration(array $update): bool
    {
        $message = $update['message'] ?? null;

        return is_array($message)
            && (isset($message['migrate_to_chat_id']) || isset($message['migrate_from_chat_id']));
    }

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        return self::isMigration($update);
    }

    public function requiresActiveGroup(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $message */
        $message = $update['message'];
        $chatId = is_numeric($message['chat']['id'] ?? null) ? (int) $message['chat']['id'] : 0;

        if (is_numeric($message['migrate_to_chat_id'] ?? null)) {
            $this->migrator->migrate($chatId, (int) $message['migrate_to_chat_id']);

            return;
        }

        if (is_numeric($message['migrate_from_chat_id'] ?? null)) {
            $this->migrator->migrate((int) $message['migrate_from_chat_id'], $chatId);
        }
    }
}
