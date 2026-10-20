<?php

declare(strict_types=1);

namespace App\Domain\Organizations\DTO;

/**
 * Seats of an organization (docs/13 §2.3 `seatsSummary`, §6.1).
 *
 * `limit` null = no live subscription grants a limit: either the subscription is perpetual /
 * unlimited (`unlimited = true`) or there is no live subscription at all (`sponsoring = false`,
 * nobody is seated by a new join). `seated` may exceed `limit` after a downgrade — nobody is
 * unseated automatically; the cabinet shows the excess.
 */
final readonly class SeatsSummary
{
    public function __construct(
        public ?int $limit,
        public int $seated,
        public int $waitlisted,
        public bool $sponsoring,
    ) {}

    public function unlimited(): bool
    {
        return $this->sponsoring && $this->limit === null;
    }

    /** Seats a new member could take right now (0 when not sponsoring or full). */
    public function free(): int
    {
        if (! $this->sponsoring) {
            return 0;
        }

        if ($this->limit === null) {
            return PHP_INT_MAX;
        }

        return max(0, $this->limit - $this->seated);
    }

    public function overLimit(): bool
    {
        return $this->limit !== null && $this->seated > $this->limit;
    }

    /** @return array{limit: int|null, seated: int, waitlisted: int, sponsoring: bool} */
    public function toArray(): array
    {
        return [
            'limit' => $this->limit,
            'seated' => $this->seated,
            'waitlisted' => $this->waitlisted,
            'sponsoring' => $this->sponsoring,
        ];
    }
}
