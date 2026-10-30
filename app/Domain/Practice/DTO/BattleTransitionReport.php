<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

use App\Domain\Practice\Services\Battle\BattleClock;

/**
 * What one call of {@see BattleClock::advance()} did (docs/16 §1.3): `changed` - the
 * row was written (a transition happened); `finalized` - the battle reached a terminal status in this call.
 */
final readonly class BattleTransitionReport
{
    public function __construct(
        public bool $changed,
        public bool $finalized,
    ) {}

    public static function nothing(): self
    {
        return new self(false, false);
    }
}
