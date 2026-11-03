<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

/**
 * The answer to `POST /game/runs/{id}/hint` (docs/18 §12.8): `hint` in the form of the kind (`options_removed` | `prefix`), the
 * `coins` after the purchase, and the current view of the run. A repeat (the same `request_id`, or a second request on the same
 * element) returns the same hint and pays nothing.
 */
final readonly class HintOutcome
{
    /** @param array<string, mixed> $body `{hint, coins: {balance}}` */
    public function __construct(
        public array $body,
        public RunView $run,
    ) {}
}
