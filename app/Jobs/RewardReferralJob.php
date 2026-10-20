<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Engagement\Services\ReferralService;
use App\Models\Referral;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Grants the referral reward to both sides (docs/13 §2.4). Idempotent: {@see ReferralService::reward()}
 * claims the referral once and records every granted side in `meta.rewards`, so a retry after a
 * partial failure (the second transaction died) only grants what is still missing, and a duplicate
 * job does nothing.
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
