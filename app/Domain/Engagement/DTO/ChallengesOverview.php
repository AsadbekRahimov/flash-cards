<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

/** GET /api/twa/challenges: open (pending / active) challenges and the latest finished ones. */
final readonly class ChallengesOverview
{
    /**
     * @param  list<ChallengeEntry>  $open
     * @param  list<ChallengeEntry>  $finished
     */
    public function __construct(
        public array $open,
        public array $finished,
        public int $days,
    ) {}
}
