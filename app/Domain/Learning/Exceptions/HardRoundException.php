<?php

declare(strict_types=1);

namespace App\Domain\Learning\Exceptions;

use App\Domain\Learning\DTO\HardRoundAvailability;
use DomainException;

/**
 * The round of hard words cannot be opened: 409 `hard_round_unavailable` with `details {reason, resets_at}` (docs/04 A.3).
 * `reason`: disabled | not_enough | played_today | no_objective_exercise ({@see HardRoundAvailability}).
 */
final class HardRoundException extends DomainException
{
    public const CODE = 'hard_round_unavailable';

    public function __construct(public readonly HardRoundAvailability $availability)
    {
        parent::__construct('The round of hard words is not available: '.($availability->reason ?? 'unknown').'.');
    }

    public function reason(): string
    {
        return $this->availability->reason ?? HardRoundAvailability::REASON_NOT_ENOUGH;
    }

    /** @return array{reason: string, resets_at: string|null} */
    public function details(): array
    {
        return ['reason' => $this->reason(), 'resets_at' => $this->availability->toArray()['resets_at']];
    }
}
