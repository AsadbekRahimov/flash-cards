<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

use App\Domain\Practice\Support\IsoTime;
use Carbon\CarbonImmutable;

/**
 * The poll of a battle (docs/16 §7.4): read every two seconds, at most 600 bytes on the wire, so it carries only what
 * moves - the status, the version, the ready flags, the presence of the opponent, the round, the scores, the end reason
 * and (once the match is over) the rematch that follows it. The client re-reads the battle when `status` changes and the
 * round when `round.no` / `round.phase` change.
 *
 * `toArray()` is the shape on the wire: the size budget is checked on it, and the resource of 18.6b returns it as is.
 */
final readonly class BattlePollView
{
    /**
     * @param  array{me: bool, opponent: bool}  $ready
     * @param  array{me: int, opponent: int}  $scores
     * @param  array{battle_id: int, status: string, by_me: bool}|null  $rematch
     */
    public function __construct(
        public int $id,
        public string $status,
        public ?string $endReason,
        public int $version,
        public CarbonImmutable $serverNow,
        public array $ready,
        public bool $opponentPresent,
        public ?CarbonImmutable $opponentAbsentSince,
        public ?RoundStateView $round,
        public array $scores,
        public ?array $rematch,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'version' => $this->version,
            'server_now' => IsoTime::format($this->serverNow),
            'ready' => $this->ready,
            'opponent_present' => $this->opponentPresent,
            'opponent_absent_since' => $this->opponentAbsentSince === null ? null : IsoTime::format($this->opponentAbsentSince),
            'round' => $this->round?->toArray(),
            'scores' => $this->scores,
            'end_reason' => $this->endReason,
            'rematch' => $this->rematch,
        ];
    }
}
