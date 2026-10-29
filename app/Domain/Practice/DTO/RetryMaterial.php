<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

/**
 * The material of "repeat the mistakes" (docs/16 §6.1): the words and the authored tasks the learner got wrong in a
 * finished session. A {@see MaterialScope} that carries it narrows the material to these ids, so the handlers of
 * the modes need no knowledge of a retry (the minimum of tasks is the one `PracticeMaterialService::minItems` returns).
 */
final readonly class RetryMaterial
{
    /**
     * @param  list<int>  $wordIds
     * @param  list<int>  $itemIds
     */
    public function __construct(public array $wordIds, public array $itemIds) {}

    public function isEmpty(): bool
    {
        return $this->wordIds === [] && $this->itemIds === [];
    }
}
