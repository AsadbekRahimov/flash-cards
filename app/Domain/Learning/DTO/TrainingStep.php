<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Domain\Content\DTO\ResolvedTranslation;
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
     * @param  string|null  $serveToken  learn-ahead ticket the client must echo in POST /review
     *                                   (docs/06 §5.2); null for due/new cards and when $word is null
     * @param  ResolvedTranslation|null  $translation  the card's translation in the session's locale (with the ru
     *                                                 fallback flag); null exactly when $word is null
     */
    public function __construct(
        public ?Word $word,
        public ?string $kind,
        public array $progress,
        public ?string $serveToken = null,
        public ?ResolvedTranslation $translation = null,
    ) {}
}
