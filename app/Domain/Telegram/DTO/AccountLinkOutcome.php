<?php

declare(strict_types=1);

namespace App\Domain\Telegram\DTO;

use App\Domain\Telegram\Enums\AccountLinkResult;
use App\Models\User;

/**
 * What a step of `/start link_<token>` did; `user` is set when the token is live (the bot names the account it asks about and
 * greets it on success), `organizations` — the names of the centers the user is staff of (the question says which cabinet).
 */
final readonly class AccountLinkOutcome
{
    /** @param list<string> $organizations */
    public function __construct(
        public AccountLinkResult $result,
        public ?User $user = null,
        public array $organizations = [],
    ) {}
}
