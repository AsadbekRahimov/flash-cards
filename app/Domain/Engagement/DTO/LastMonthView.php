<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

/**
 * The previous month as the Mini App shows it (docs/13 §7.6): the confirmed winners (empty until the administrator
 * confirms — "the results are being checked"), and the learner's own final place when they took part.
 */
final readonly class LastMonthView
{
    /**
     * @param  string  $status  `league_months.status`
     * @param  list<array{place: int, name: string|null, points: int}>  $winners  by place; empty unless `confirmed`
     * @param  array{rank: int, points: int}|null  $me
     */
    public function __construct(
        public string $startsOn,
        public string $endsOn,
        public string $status,
        public array $winners,
        public ?array $me,
    ) {}
}
