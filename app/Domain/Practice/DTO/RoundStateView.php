<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

use App\Domain\Practice\Support\IsoTime;
use Carbon\CarbonImmutable;

/**
 * The round a battle is in, as both the full view and the poll show it (docs/16 §7.4): the number, the phase (`null` in
 * the countdown - the round has its number and its deadline, but no phase before the match starts, §8.1), the end of the
 * time to answer, the end of the pause that shows the answers, and who has answered (the opponent's answer itself is not
 * told until the round is resolved, §8.5).
 */
final readonly class RoundStateView
{
    public function __construct(
        public int $no,
        public ?string $phase,
        public ?CarbonImmutable $deadlineAt,
        public ?CarbonImmutable $phaseEndsAt,
        public bool $myAnswered,
        public bool $opponentAnswered,
    ) {}

    /** @return array{no: int, phase: string|null, deadline_at: string|null, phase_ends_at: string|null, my_answered: bool, opponent_answered: bool} */
    public function toArray(): array
    {
        return [
            'no' => $this->no,
            'phase' => $this->phase,
            'deadline_at' => $this->deadlineAt === null ? null : IsoTime::format($this->deadlineAt),
            'phase_ends_at' => $this->phaseEndsAt === null ? null : IsoTime::format($this->phaseEndsAt),
            'my_answered' => $this->myAnswered,
            'opponent_answered' => $this->opponentAnswered,
        ];
    }
}
