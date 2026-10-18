<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Models\Word;

/**
 * What the training screen should show next: a card (or nothing — "done")
 * plus the lesson progress.
 */
final readonly class TrainingStep
{
    /**
     * @param  'due'|'new'|'learning'|null  $kind  null exactly when $word is null
     * @param  array{done:int, total:int}  $progress
     */
    public function __construct(
        public ?Word $word,
        public ?string $kind,
        public array $progress,
    ) {}
}
