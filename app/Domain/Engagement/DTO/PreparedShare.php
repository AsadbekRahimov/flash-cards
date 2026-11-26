<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use Carbon\CarbonImmutable;

/**
 * A message prepared for `WebApp.shareMessage` (docs/36 §1в): the id of Telegram and when it stops working. `cached` = served from the
 * cache (or by a parallel request) without a call to the Bot API.
 */
final readonly class PreparedShare
{
    public function __construct(
        public string $id,
        public CarbonImmutable $expiresAt,
        public bool $cached,
    ) {}
}
