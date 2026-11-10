<?php

declare(strict_types=1);

namespace App\Domain\Telegram\DTO;

use App\Domain\Telegram\Enums\AccountLinkResult;
use App\Models\User;

/** What `/start link_<token>` did; `user` is set on success (the bot greets them by name). */
final readonly class AccountLinkOutcome
{
    public function __construct(
        public AccountLinkResult $result,
        public ?User $user = null,
    ) {}
}
