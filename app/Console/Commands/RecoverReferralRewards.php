<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Engagement\Services\ReferralService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * `referrals:recover` (docs/13 §7.1): re-queues the jobs of referrals left half-way — `activated` with no job behind
 * it, or a milestone reached and not granted. Scheduled every 30 minutes; idempotent (the jobs are, by the data in
 * `referrals.status` and `referral_milestones.granted_at`).
 */
final class RecoverReferralRewards extends Command
{
    protected $signature = 'referrals:recover';

    protected $description = 'Re-queue the jobs of referrals never counted and of milestones never granted';

    public function handle(ReferralService $referrals): int
    {
        $queued = $referrals->recoverStuck(CarbonImmutable::now());

        $this->info("Referral reward jobs queued: {$queued}");

        return self::SUCCESS;
    }
}
