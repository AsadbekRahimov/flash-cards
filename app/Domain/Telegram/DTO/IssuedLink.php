<?php

declare(strict_types=1);

namespace App\Domain\Telegram\DTO;

use App\Domain\Telegram\Enums\LinkTokenKind;
use Carbon\CarbonImmutable;

/**
 * A freshly issued deep link of the cabinet. `code` is the raw token — shown to the staff member once (the fallback
 * `/link <code>` for groups) and never stored; `url` opens Telegram.
 */
final readonly class IssuedLink
{
    public function __construct(
        public LinkTokenKind $kind,
        public string $code,
        public string $url,
        public CarbonImmutable $expiresAt,
        public int $tokenId,
    ) {}

    /** What the person types in the group when the deep link cannot be used: `/link <code>`. */
    public function command(): string
    {
        return '/link '.$this->code;
    }
}
