<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

/**
 * The answer to `POST /game/runs/{id}/answer` (docs/18 §12.8): the stored body of the attempt (`verdict, points, reveal, flask_lost,
 * combo, event_applied`, and `pair` / `round_done` for a round of pairs) - a repeat of the `request_id` returns it byte for byte - and
 * the CURRENT view of the run (a repeat gets the view of now, not of then).
 */
final readonly class RunAnswerOutcome
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public array $body,
        public RunView $run,
    ) {}
}
