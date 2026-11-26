<?php

declare(strict_types=1);

namespace App\Domain\Telegram\DTO;

/**
 * A user's place in a chat as `getChatMember` answers it (docs/33 §4.2, K1): the status (`creator`, `administrator`, `member`,
 * `restricted`, `left`, `kicked`) and `is_member`. Telegram sends `is_member` only for `restricted` - a muted user who LEFT the chat
 * stays `restricted` with `is_member = false` - so for the other statuses it follows from the status itself. `isMember` is «член
 * чата» of docs/33 §4.2: creator, administrator, member, or restricted while still in the chat.
 */
final readonly class ChatMemberInfo
{
    public function __construct(
        public string $status,
        public bool $isMember,
    ) {}

    /** @param array<string, mixed> $member a `ChatMember` object of the Bot API */
    public static function fromArray(array $member): self
    {
        $status = is_string($member['status'] ?? null) ? $member['status'] : '';

        $isMember = match ($status) {
            'creator', 'administrator', 'member' => true,
            'restricted' => ($member['is_member'] ?? false) === true,
            default => false,
        };

        return new self($status, $isMember);
    }
}
