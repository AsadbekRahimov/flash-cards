<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An inviter's milestone of "Invite a friend" was settled (docs/13 §7.1 "2C.9"): `$milestone` counted friends,
 * `$days` of Pro granted as `$subscriptionId` — or nothing, `$skipped` says why (`perpetual`, `learner_deleted`,
 * `learner_blocked`, `learner_missing`). Raised AFTER the commit of the grant transaction, once per
 * (inviter, milestone). The bot's "your N-th friend started — +7 days of Pro" DM hangs on it (a listener that queues
 * the message; the inviter is `$learnerId`).
 */
final class ReferralMilestoneReached
{
    use Dispatchable;

    public function __construct(
        public readonly int $learnerId,
        public readonly int $milestone,
        public readonly int $days,
        public readonly ?int $subscriptionId,
        public readonly ?string $skipped,
    ) {}
}
