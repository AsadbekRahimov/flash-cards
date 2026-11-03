<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

use App\Domain\Practice\DTO\LocaleSet;

/**
 * Everything a run may be made of (docs/18 §12.3.1), read before the transaction by `RunSnapshotBuilder` and handed to the pure
 * `RunComposer`: the words of the stop (or of the city, `scopeWidened`), the authored tasks already built by the handlers of the
 * practice, the distractors of the course and of the public courses, and the locales the snapshot is built for.
 */
final readonly class RunPool
{
    /**
     * @param  list<RunPoolWord>  $words  the words the elements are made of (tier 0; they are also each other's nearest distractors)
     * @param  list<AuthoredCandidate>  $authored  the authored tasks, in the order the builder picked them (already no more than the limit)
     * @param  list<RunPoolWord>  $distractors  the words of the course (tier 1) and of the public courses (tier 2), not among `words`
     */
    public function __construct(
        public LocaleSet $locales,
        public array $words,
        public array $authored = [],
        public array $distractors = [],
        public bool $scopeWidened = false,
    ) {}
}
