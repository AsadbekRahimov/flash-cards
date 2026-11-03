<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

use App\Domain\Game\Enums\RunEvent;
use App\Domain\Practice\DTO\SnapshotElement;

/**
 * What `RunComposer::compose()` returns (docs/18 §12.3.1): the elements in the order of play and the events fixed for the run -
 * `[{at: index, type: sandstorm | oasis | bazaar}]`, no more than one in G2.
 */
final readonly class RunComposition
{
    /**
     * @param  list<SnapshotElement>  $elements
     * @param  list<array{at: int, type: string}>  $events
     */
    public function __construct(
        public array $elements,
        public array $events = [],
    ) {}

    public function count(): int
    {
        return count($this->elements);
    }

    public function eventAt(int $index): ?RunEvent
    {
        foreach ($this->events as $event) {
            if ($event['at'] === $index) {
                return RunEvent::tryFrom($event['type']);
            }
        }

        return null;
    }
}
