<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Domain\Learning\Services\NewWordFrontier;

/**
 * What {@see NewWordFrontier::read()} found for one (learner, course): the key it was read under - built with the data
 * version of the course BEFORE any row of it was read, and the only key the result of this pick may be written to - and
 * the value, if any.
 *
 * `lessonId`: null - no hint (nothing cached, or it expired); {@see NewWordFrontier::NONE_LEFT} (0) - the course had no
 * unseen active word left when the full query last ran; otherwise the lesson where that query found the first one.
 */
final readonly class NewWordFrontierHint
{
    public function __construct(
        public string $key,
        public ?int $lessonId,
    ) {}

    /** The course was walked to the end: no transaction, no query (until the version moves or the entry expires). */
    public function isExhausted(): bool
    {
        return $this->lessonId === NewWordFrontier::NONE_LEFT;
    }

    /** The lesson to look in first, or null when the full query has to run. */
    public function lesson(): ?int
    {
        return $this->lessonId !== null && $this->lessonId > 0 ? $this->lessonId : null;
    }
}
