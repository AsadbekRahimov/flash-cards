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
    /**
     * The longest pause between two polls of the TWA (`usePolling`, `maxIntervalMs`): the beat is 2 s, and a failed poll doubles
     * it up to this. With `heartbeat_write_seconds` it bounds how old the mark of a player who is HERE can be - the floor of the grace.
     */
    public const POLL_MAX_PAUSE_SECONDS = 10;

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

    /**
     * The silence that ends a match (docs/16 §8.4), never below {@see presenceGraceFloorSeconds()}. The heartbeat of a player is
     * written at most once per `heartbeat_write_seconds` and the poll beats every 2 s (up to 10 s after a failed one), so the mark
     * of a player who is here is up to ~7 s old, up to ~15 s after a failed poll: a grace below the floor would cancel healthy
     * matches in the countdown (the 5 s of an earlier docs/16 §14.4 did, on the e2e stand - 18.12a). `PRACTICE_BATTLE_PRESENCE_GRACE`
     * speeds a stand up only down to the floor; the screens read the effective value from the battle.
     */
    public function presenceGraceSeconds(): int
    {
        return max($this->int('presence_grace_seconds', 30), $this->presenceGraceFloorSeconds());
    }

    /** `heartbeat_write_seconds` + the longest pause of the poll: the mark of a player who is here is never older than this. */
    public function presenceGraceFloorSeconds(): int
    {
        return $this->heartbeatWriteSeconds() + self::POLL_MAX_PAUSE_SECONDS;
    }

    /**
     * The silence after which the screens warn that the opponent has lost the connection (`opponent_present = false`, docs/16 §8.4).
     * Never longer than the grace: it is a warning that comes BEFORE the end, and the match ends only at {@see presenceGraceSeconds()}.
     */
    public function presenceWarnSeconds(): int
    {
        return min($this->int('presence_warn_seconds', 10), $this->presenceGraceSeconds());
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
