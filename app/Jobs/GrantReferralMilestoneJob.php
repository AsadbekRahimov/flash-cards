<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Engagement\Services\ReferralService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Settles one reached milestone of "Invite a friend" whose grant did not happen (docs/13 §7.1 "2C.9"): queued by
 * `referrals:recover` for a `referral_milestones` row left with `granted_at IS NULL`. Idempotent:
 * {@see ReferralService::grantMilestone()} re-checks the row under its lock and does nothing for a settled one.
 */
final class GrantReferralMilestoneJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly int $milestoneId) {}

    public function handle(ReferralService $referrals): void
    {
        $referrals->grantMilestone($this->milestoneId, CarbonImmutable::now());
    }
}
