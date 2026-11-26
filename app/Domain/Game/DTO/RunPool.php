<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

use App\Domain\Practice\DTO\LocaleSet;

/**
 * Everything a run may be made of (docs/18 §12.3.1), read before the transaction by `RunSnapshotBuilder` and handed to the pure
 * `RunComposer`: the words of the stop (or of the city, `scopeWidened`), the authored tasks already built by the handlers of the
 * practice, the distractors of the course, the locales the snapshot is built for - and the distractors of the public courses,
 * which are read only if the composer runs short of the nearer ones ({@see PublicDistractors}).
 */
final readonly class RunPool
{
    /**
     * @param  list<RunPoolWord>  $words  the words the elements are made of (tier 0; they are also each other's nearest distractors)
     * @param  list<AuthoredCandidate>  $authored  the authored tasks, in the order the builder picked them (already no more than the limit)
     * @param  list<RunPoolWord>  $distractors  the words of the course (tier 1), not among `words`; a list may carry words of the
     *                                          public courses (tier 2) too - they are ranked after the course all the same
     * @param  PublicDistractors|null  $public  the words of the public courses (tier 2), asked for only when a word did not find its
     *                                          three distractors in the nearer tiers
     */
    public function __construct(
        public LocaleSet $locales,
        public array $words,
        public array $authored = [],
        public array $distractors = [],
        public bool $scopeWidened = false,
        public ?PublicDistractors $public = null,
    ) {}
}
