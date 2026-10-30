<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services\Battle;

use App\Domain\Practice\Enums\PracticeMode;

/**
 * The numbers of the battle from `config/practice.php` (`practice.battle.*`, docs/16 §3.1), read in one place and never
 * below one. The clocks of a match are computed from them on the server; the clients only display them.
 */
final class BattleSettings
{
    public function rounds(): int
    {
        return $this->int('rounds', 5);
    }

    public function roundSeconds(PracticeMode $mode): int
    {
        return max(1, (int) config("practice.battle.round_seconds.{$mode->value}", 45));
    }

    public function countdownSeconds(): int
    {
        return $this->int('countdown_seconds', 3);
    }

    public function revealSeconds(): int
    {
        return $this->int('reveal_seconds', 4);
    }

    public function inviteTtlSeconds(): int
    {
        return $this->int('invite_ttl_seconds', 300);
    }

    public function roomTtlSeconds(): int
    {
        return $this->int('room_ttl_seconds', 120);
    }

    public function presenceGraceSeconds(): int
    {
        return $this->int('presence_grace_seconds', 30);
    }

    public function heartbeatWriteSeconds(): int
    {
        return $this->int('heartbeat_write_seconds', 5);
    }

    /** The answer that leaves the device at 0:00 and arrives a little later is still taken (docs/16 §8.5). */
    public function deadlineGraceMs(): int
    {
        return max(0, (int) config('practice.battle.deadline_grace_ms', 1500));
    }

    /** The most points of a round (`practice.battle.points.round`): what a handler reports is clamped to 0..this. */
    public function roundPoints(): int
    {
        return max(0, (int) config('practice.battle.points.round', 100));
    }

    public function finishedList(): int
    {
        return $this->int('finished_list', 10);
    }

    private function int(string $key, int $default): int
    {
        return max(1, (int) config("practice.battle.{$key}", $default));
    }
}
