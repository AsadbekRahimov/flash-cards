<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Enums;

/** What a `telegram_link_tokens` row is for (docs/13 §6.2). */
enum LinkTokenKind: string
{
    /** `t.me/<bot>?start=link_<token>` — connects the staff user's own Telegram account. */
    case Account = 'account';

    /** `t.me/<bot>?startgroup=grp_<token>` or `/link <token>` in the group — links the group to the organization. */
    case Group = 'group';

    /** The prefix of the `/start` deep-link parameter. */
    public function prefix(): string
    {
        return match ($this) {
            self::Account => 'link_',
            self::Group => 'grp_',
        };
    }
}
