<?php

declare(strict_types=1);

namespace App\Domain\Organizations\DTO;

use App\Domain\Organizations\Enums\GroupLinkResult;
use App\Models\Organization;
use App\Models\TelegramGroup;

/**
 * What a `/start grp_<token>` / `/link <token>` in a chat did. `group` and `organization` are set on success;
 * `activated` says whether the group was switched to `active` right away (center with a live subscription).
 */
final readonly class GroupLinkOutcome
{
    public function __construct(
        public GroupLinkResult $result,
        public ?TelegramGroup $group = null,
        public ?Organization $organization = null,
        public bool $activated = false,
    ) {}

    public static function refused(GroupLinkResult $result): self
    {
        return new self($result);
    }
}
