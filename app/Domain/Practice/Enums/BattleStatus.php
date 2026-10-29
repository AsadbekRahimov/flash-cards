<?php

declare(strict_types=1);

namespace App\Domain\Practice\Enums;

/**
 * The status of a battle (`battles.status`, docs/16 §2.7, §8.1): invited -> room -> countdown -> playing ->
 * finished (there is a result: a win or a draw) | closed (no result).
 */
enum BattleStatus: string
{
    case Invited = 'invited';
    case Room = 'room';
    case Countdown = 'countdown';
    case Playing = 'playing';
    case Finished = 'finished';
    case Closed = 'closed';

    public function isTerminal(): bool
    {
        return $this === self::Finished || $this === self::Closed;
    }

    /** A match that is on: the players are in the room, in the countdown or in the rounds. */
    public function isLive(): bool
    {
        return $this === self::Room || $this === self::Countdown || $this === self::Playing;
    }

    /** @return list<string> the statuses of a match that is not over (the player is busy with it) */
    public static function nonTerminalValues(): array
    {
        return [self::Invited->value, self::Room->value, self::Countdown->value, self::Playing->value];
    }
}
