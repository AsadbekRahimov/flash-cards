<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

/**
 * The outcome of an accepted answer (docs/16 §6.3): the stored `result` (`verdict`, `first_attempt`, `reveal`, `pair` and,
 * for pairs, `round_done` - exactly what `practice_attempts.result` holds, so a repeat of the request returns the very
 * same body) and the CURRENT view of the session.
 */
final readonly class AnswerOutcome
{
    /** @param array<string, mixed> $result */
    public function __construct(
        public array $result,
        public SessionView $session,
    ) {}
}
