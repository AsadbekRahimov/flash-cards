<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Domain\Learning\Services\GapFillBuilder;

/**
 * A word's example with one occurrence of the word (or an inflected form) masked.
 *
 * @param  string  $masked  the example with the form replaced by {@see GapFillBuilder::MASK}
 * @param  string  $expected  the form that was masked, as it stands in the example (the grader normalizes the case)
 */
final readonly class GapFill
{
    public function __construct(
        public string $masked,
        public string $expected,
    ) {}
}
