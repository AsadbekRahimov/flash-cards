<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Engagement\Services\ReferralService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * `referrals:recover` (docs/13 §7.1): re-queues the reward job of referrals left half-way — `activated`
 * with no job behind it, or `rewarded` with one side not granted. Scheduled every 30 minutes; idempotent
 * (the reward itself is, by the data in `referrals.meta.rewards`).
 */
final class RecoverReferralRewards extends Command
{
    protected $signature = 'referrals:recover';

    protected $description = 'Re-queue the reward job of referrals whose reward was never fully granted';

    public function handle(ReferralService $referrals): int
    {
        $queued = $referrals->recoverStuck(CarbonImmutable::now());

        $this->info("Referral reward jobs queued: {$queued}");

        return self::SUCCESS;
    }
}
