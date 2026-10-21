<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Models\ExamSession;

/**
 * Outcome of a finalized personal mock (docs/13 §8.4).
 *
 * `percent` — correct answers over ALL questions of the mock (an unanswered question is wrong).
 * `percentile` — share (0..100) of closed mocks of the same course over the last N days whose
 * percent is at or below this one, this mock included; null while the mock is still open.
 * `attempts` — size of that distribution (this mock included).
 */
final readonly class MockExamResult
{
    public function __construct(
        public ExamSession $session,
        public int $correct,
        public int $total,
        public int $answered,
        public int $percent,
        public ?int $percentile,
        public int $attempts,
    ) {}
}
