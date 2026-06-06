<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers\Contracts;

interface UpdateHandler
{
    /** @param array<string, mixed> $update */
    public function matches(array $update): bool;

    /** @param array<string, mixed> $update */
    public function handle(array $update): void;

    /**
     * Whether this handler may only run for messages originating from an
     * active (whitelisted) Telegram group. The dispatcher enforces the
     * Group Lock (FR-BOT-01) centrally: group-chat updates for which this
     * returns true are dropped silently unless the chat is an active group.
     * Lifecycle handlers (my_chat_member, new members) and private-chat
     * handlers return false so they keep working outside the whitelist.
     */
    public function requiresActiveGroup(): bool;
}
