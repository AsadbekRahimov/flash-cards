<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

use App\Domain\Practice\Support\IsoTime;
use Carbon\CarbonImmutable;

/**
 * One Free limit of the day (docs/18 G1): how many `kind` (`sessions` | `battles`) the learner may create in the window,
 * how many are used, and when the window ends (`resets_at` - the end of the learner's local day, carried over after a change
 * of timezone, the same window as the quota of new words: LearnerClock::limitWindowUtc). The client shows `remaining` and
 * the paywall `practice` when it is 0.
 */
final readonly class PracticeLimitState
{
    public function __construct(
        public string $kind,
        public int $limit,
        public int $used,
        public CarbonImmutable $resetsAt,
    ) {}

    public function remaining(): int
    {
        return max(0, $this->limit - $this->used);
    }

    public function isReached(): bool
    {
        return $this->used >= $this->limit;
    }

    /** @return array{limit: int, used: int, remaining: int, resets_at: string} */
    public function toArray(): array
    {
        return [
            'limit' => $this->limit,
            'used' => $this->used,
            'remaining' => $this->remaining(),
            'resets_at' => IsoTime::format($this->resetsAt),
        ];
    }
}
