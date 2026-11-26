<?php

declare(strict_types=1);

namespace App\Domain\Cup\DTO;

/**
 * An accepted answer (docs/33 §7.2 `CupRoundAnswerResource`): `body` is what was stored with the answer - `{verdict, points, reveal,
 * finished, total_points}`, the same bytes for a repeated `request_id` - and `view` the attempt after it (the next task or the review).
 */
final readonly class CupAnswerOutcome
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public array $body,
        public CupRoundView $view,
        public bool $replayed,
    ) {}
}
