<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

/** The daily limits of one category in one rules version (docs/28 «Лимиты и периоды»): distinct new words, distinct repetitions, the day cap. */
final readonly class ScoreLimits
{
    public function __construct(
        public int $new,
        public int $review,
        public int $dayCap,
    ) {}
}
