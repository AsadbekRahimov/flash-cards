<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Models\Referral;
use Illuminate\Database\Eloquent\Collection;

/**
 * The inviter's view of "Invite a friend" (docs/04 A.12, `/invite`): their code and link, the rule
 * and the reward, the counts and the latest invited learners (first names only — no other data of
 * other people leaves the server).
 */
final readonly class ReferralOverview
{
    /** @param Collection<int, Referral> $referrals non-rejected, newest first, `referee` loaded */
    public function __construct(
        public string $code,
        public ?string $link,
        public int $rewardDays,
        public int $activationDays,
        public int $activationReviewsPerDay,
        public int $activationWindowDays,
        public int $invited,
        public int $activated,
        public int $rewarded,
        public Collection $referrals,
    ) {}
}
