<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\PracticeItemType;

/**
 * An authored task ready for a run (docs/18 §12.3.1): the element the handler of the practice built for it (through
 * `PracticeTaskAdapter`, before the transaction) and the words it targets - they are marked "used" by the composer, and an error
 * on the task brings every one of them closer in the cards (docs/18 §12.4). The element keeps `target_word_ids` in its data for that.
 */
final readonly class AuthoredCandidate
{
    /** @param list<int> $targetWordIds */
    public function __construct(
        public SnapshotElement $element,
        public PracticeItemType $type,
        public int $itemId,
        public array $targetWordIds,
    ) {}
}
