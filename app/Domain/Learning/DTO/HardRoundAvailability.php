<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use Carbon\CarbonImmutable;

/**
 * Whether the round of hard words can be opened (or continued) right now (docs/06 §14) - the `hard_round` block of
 * `GET /today` and the `hard_round_offer` of `/finish`.
 */
final readonly class HardRoundAvailability
{
    public const REASON_DISABLED = 'disabled';

    public const REASON_NOT_ENOUGH = 'not_enough';

    public const REASON_PLAYED_TODAY = 'played_today';

    public const REASON_NO_OBJECTIVE_EXERCISE = 'no_objective_exercise';

    /**
     * @param  string|null  $reason  why not, when `available` is false: one of the REASON_* constants
     * @param  int  $words  the size of the round that opens (the live one: its set); 0 when it cannot
     * @param  int|null  $openSessionId  the live `hard_review` session begun today, to continue
     * @param  CarbonImmutable|null  $resetsAt  the end of the learner's day window, with `played_today`
     */
    public function __construct(
        public bool $available,
        public ?string $reason = null,
        public int $words = 0,
        public ?int $openSessionId = null,
        public ?CarbonImmutable $resetsAt = null,
    ) {}

    /** @return array{available: bool, reason: string|null, words: int, open_session_id: int|null, resets_at: string|null} */
    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'reason' => $this->reason,
            'words' => $this->words,
            'open_session_id' => $this->openSessionId,
            'resets_at' => $this->resetsAt?->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
