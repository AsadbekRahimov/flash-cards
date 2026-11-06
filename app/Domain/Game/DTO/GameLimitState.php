<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

use App\Domain\Practice\Support\IsoTime;
use Carbon\CarbonImmutable;

/**
 * The Free limits of a learner at an instant (docs/18 §12.7.2) and the balances they go with - read in ONE statement by
 * `GameLimits::state()`. A limit that is `null` does not apply to the learner (Pro has the feature that lifts it, or the config
 * leaves it empty): the block says `null` for it. `toArray()` is the block `limits` of `/me.game` (docs/04 A.2); `coins` is not part
 * of it - it rides along so that `/me` pays one query for the whole `game` block.
 *
 * @phpstan-type LimitBlock array{limit: int, used: int, remaining: int, resets_at: string}
 * @phpstan-type LimitsBlock array{runs: LimitBlock|null, tickets: array{balance: int, max: int}, boosters: LimitBlock|null, boss_stages: int|null}
 */
final readonly class GameLimitState
{
    public function __construct(
        public ?int $runsLimit,
        public int $runsUsed,
        public CarbonImmutable $runsResetAt,
        public int $tickets,
        public int $ticketsMax,
        public ?int $boostersLimit,
        public int $boostersUsed,
        public CarbonImmutable $boostersResetAt,
        public ?int $bossStages,
        public int $coins,
        public int $stopsCleared = 0,
        public int $citiesCleared = 0,
    ) {}

    public function runsRemaining(): int
    {
        return $this->runsLimit === null ? PHP_INT_MAX : max(0, $this->runsLimit - $this->runsUsed);
    }

    /** The runs of the day are used up and only a ticket (or Pro) buys another. */
    public function runsReached(): bool
    {
        return $this->runsLimit !== null && $this->runsUsed >= $this->runsLimit;
    }

    public function boostersReached(): bool
    {
        return $this->boostersLimit !== null && $this->boostersUsed >= $this->boostersLimit;
    }

    /** @return LimitsBlock */
    public function toArray(): array
    {
        return [
            'runs' => $this->runsLimit === null ? null : self::block($this->runsLimit, $this->runsUsed, $this->runsResetAt),
            'tickets' => ['balance' => $this->tickets, 'max' => $this->ticketsMax],
            'boosters' => $this->boostersLimit === null ? null : self::block($this->boostersLimit, $this->boostersUsed, $this->boostersResetAt),
            'boss_stages' => $this->bossStages,
        ];
    }

    /** @return LimitBlock */
    private static function block(int $limit, int $used, CarbonImmutable $resetsAt): array
    {
        return ['limit' => $limit, 'used' => $used, 'remaining' => max(0, $limit - $used), 'resets_at' => IsoTime::format($resetsAt)];
    }
}
