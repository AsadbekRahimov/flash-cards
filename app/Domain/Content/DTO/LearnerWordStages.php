<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

/**
 * The learner's own rows over the active words of a course by stage (docs/06 §13.3): `started` - words they have a row for,
 * `learned` - of them in `review` with an interval from the "known" threshold, `mastered` - from the "mastered" one (a subset of `learned`).
 */
final readonly class LearnerWordStages
{
    public function __construct(
        public int $started,
        public int $learned,
        public int $mastered,
    ) {}
}
