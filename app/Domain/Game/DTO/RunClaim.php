<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

use App\Domain\Game\Enums\FreeSlot;

/**
 * What pays for a map run (docs/18 §12.7.2), decided by `GameLimits::claimRun()` under the lock of the learner's row: the free run of
 * the day (`daily`), a ticket (`ticket` - the caller spends it in the same transaction, after the INSERT of the run), or nothing
 * (`null`: Pro, the daily caravan, a boss of a class, no limit configured). `free_slot` of `game_runs` and of `game.run_started`.
 */
final readonly class RunClaim
{
    private function __construct(public ?FreeSlot $freeSlot) {}

    public static function free(): self
    {
        return new self(null);
    }

    public static function dailySlot(): self
    {
        return new self(FreeSlot::Daily);
    }

    public static function ticket(): self
    {
        return new self(FreeSlot::Ticket);
    }

    public function spendsTicket(): bool
    {
        return $this->freeSlot === FreeSlot::Ticket;
    }
}
