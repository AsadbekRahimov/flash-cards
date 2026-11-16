<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Engagement\Services\ReferralService;
use App\Models\Referral;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Counts an activated referral for its inviter and settles the milestones it completes (docs/13 §2.4, §7.1 "2C.9").
 * Idempotent: {@see ReferralService::reward()} claims the referral once (`activated` → `rewarded`), the milestone
 * rows are unique per (inviter, milestone) and each is granted under its own lock, so a retry after a partial
 * failure settles only what is still owed, and a duplicate job does nothing.
 */
final class RewardReferralJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly int $referralId) {}

    public function handle(ReferralService $referrals): void
    {
        /** @var Referral|null $referral */
        $referral = Referral::query()->find($this->referralId);
        if ($referral === null) {
            return;
        }

        $referrals->reward($referral, CarbonImmutable::now());
    }
}
