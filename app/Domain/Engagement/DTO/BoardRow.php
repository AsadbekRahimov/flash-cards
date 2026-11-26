<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Services\Scoring\ScoreBoard;

/**
 * One row of a table of the word points v2 ({@see ScoreBoard}: a category of a month, later "all time"): the place (the
 * competition rank of `(points, longWords, activeDays)`: 1, 2, 2, 4), the learner's first name (null when deleted or empty - nothing
 * else of other people leaves the server), the tie-break facts and - filled by the reader of a page, not by the board - the emoji of Pro
 * (docs/31 §5а; null without a live Pro).
 *
 * @phpstan-type BoardRowArray array{learner_id: int, name: string|null, points: int, long_words: int, active_days: int, rank: int, pro_emoji?: string|null}
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
        public ?string $proEmoji = null,
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
            'pro_emoji' => $this->proEmoji,
        ];
    }

    /** @param  BoardRowArray  $data */
    public static function fromArray(array $data): self
    {
        return new self((int) $data['learner_id'], $data['name'], (int) $data['points'], (int) $data['long_words'], (int) $data['active_days'], (int) $data['rank'], $data['pro_emoji'] ?? null);
    }

    /** The same row with the emoji of Pro of its learner (null - none to show). */
    public function withEmoji(?string $emoji): self
    {
        return new self($this->learnerId, $this->name, $this->points, $this->longWords, $this->activeDays, $this->rank, $emoji);
    }
}
