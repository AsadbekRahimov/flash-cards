<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Services\Scoring\SessionScoreService;

/**
 * What a training session brought in word points (docs/32 §8.2, `POST /finish.score_session`; {@see SessionScoreService}):
 *
 *   earned          - the points of the session's credits by the rules (new 2, review 3, long 5), before the limits;
 *   weekAdded / monthAdded - what the weekly / monthly count really got (`score_credits.week_points` / `month_points`);
 *   cappedOff       - what the limits of the day cut off: `earned` minus what the shown count got;
 *   weekRatingDelta - how the week's sum (the best days, §3.5) grew by the session: the best days now minus the best days with the
 *                     session's points taken out of their days - "+70 for the session, +40 to the weekly rating";
 *   today           - the rating day after the session ("Today N of 100"), null while the learner has no row today.
 */
final readonly class SessionScore
{
    public function __construct(
        public int $earned,
        public int $weekAdded,
        public int $monthAdded,
        public int $cappedOff,
        public int $weekRatingDelta,
        public bool $weekCounts,
        public ?ScoreToday $today,
    ) {}

    /** @return array{earned: int, week_added: int, month_added: int, week_rating_delta: int, capped_off: int, today: array<string, mixed>|null} */
    public function toArray(): array
    {
        return [
            'earned' => $this->earned,
            'week_added' => $this->weekAdded,
            'month_added' => $this->monthAdded,
            'week_rating_delta' => $this->weekRatingDelta,
            'capped_off' => $this->cappedOff,
            'today' => $this->today?->toArray(),
        ];
    }
}
