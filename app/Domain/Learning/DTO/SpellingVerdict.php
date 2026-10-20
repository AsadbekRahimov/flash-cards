<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Domain\Learning\Services\SpellingGrader;

/**
 * Result of {@see SpellingGrader::grade()}.
 *
 * @param  int  $quality  the SM-2 grade the server applies: 5 exact, 4 within the tolerance, 1 otherwise
 * @param  int  $distance  optimal string alignment distance between the normalized texts
 * @param  string  $normalizedExpected  what the answer was compared with (shown to the learner as the right answer)
 */
final readonly class SpellingVerdict
{
    public function __construct(
        public int $quality,
        public int $distance,
        public string $normalizedExpected,
        public string $normalizedAnswer,
    ) {}

    public function isCorrect(): bool
    {
        return $this->quality >= 4;
    }
}
