<?php

declare(strict_types=1);

namespace App\Domain\Cup\DTO;

/**
 * What a participant sees of his attempt of the day (docs/33 §5.2, §7.2 `CupRoundResource`), built by `CupRoundService` from the
 * row and the day's snapshot - nothing here comes from the client. `current` is the projection of the next task (no right answer,
 * the options in the participant's own order) or null; `answers` are the accepted ones in the order of play; `review` (only once
 * the attempt is over) is the right answer of every task.
 */
final readonly class CupRoundView
{
    /**
     * @param  array<string, mixed>|null  $current
     * @param  list<array{element_id: string, verdict: string, points: int}>  $answers
     * @param  list<array<string, mixed>>|null  $review
     */
    public function __construct(
        public int $attemptId,
        public int $cupId,
        public int $participantId,
        public int $day,
        public string $status,
        public ?string $finishReason,
        public int $total,
        public int $answered,
        public int $correct,
        public int $points,
        public int $maxPoints,
        public int $position,
        public string $startedAt,
        public string $expiresAt,
        public string $serverNow,
        public ?array $current,
        public array $answers,
        public ?array $review,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'attempt_id' => $this->attemptId,
            'cup_id' => $this->cupId,
            'participant_id' => $this->participantId,
            'day' => $this->day,
            'status' => $this->status,
            'finish_reason' => $this->finishReason,
            'total' => $this->total,
            'answered' => $this->answered,
            'correct' => $this->correct,
            'points' => $this->points,
            'max_points' => $this->maxPoints,
            'position' => $this->position,
            'started_at' => $this->startedAt,
            'expires_at' => $this->expiresAt,
            'server_now' => $this->serverNow,
            'current' => $this->current,
            'answers' => $this->answers,
            'review' => $this->review,
        ];
    }
}
