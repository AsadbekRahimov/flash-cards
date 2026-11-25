<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Services\Scoring\ScoreBoard;

/**
 * One row of a table of the word points v2 ({@see ScoreBoard}: a category of a month, later "all time"): the place (the
 * competition rank of `(points, longWords, activeDays)`: 1, 2, 2, 4), the learner's first name (null when deleted or empty - nothing
 * else of other people leaves the server) and the tie-break facts.
 *
 * @phpstan-type BoardRowArray array{learner_id: int, name: string|null, points: int, long_words: int, active_days: int, rank: int}
 */
final readonly class BoardRow
{
    public function __construct(
        public int $learnerId,
        public ?string $name,
        public int $points,
        public int $longWords,
        public int $activeDays,
        public int $rank,
    ) {}

    /** The keyset position of the row - what {@see ScoreBoard::page()} continues after. */
    public function cursor(): BoardCursor
    {
        return new BoardCursor($this->points, $this->longWords, $this->activeDays, $this->learnerId);
    }

    /** @return BoardRowArray */
    public function toArray(): array
    {
        return [
            'learner_id' => $this->learnerId,
            'name' => $this->name,
            'points' => $this->points,
            'long_words' => $this->longWords,
            'active_days' => $this->activeDays,
            'rank' => $this->rank,
        ];
    }
}
