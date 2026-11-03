<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

use App\Models\Learner;
use Carbon\CarbonImmutable;

/**
 * The first phase of the claim of a Free map run (docs/18 §12.7.2, "two phases"): the learner's row is locked, the limit is known,
 * the count has NOT been made yet - it is made by `GameLimits::claimRun()` once the caller has dealt with the active run (an active
 * run of the same stop is returned as it is and takes no new place; one closed without an answer gives its place back). Only a
 * learner with a limit gets one of these; `null` from `lockForRun()` means nothing applies and no lock was taken.
 */
final readonly class PendingRunClaim
{
    public function __construct(
        public Learner $learner,
        public int $limit,
        public CarbonImmutable $now,
    ) {}
}
