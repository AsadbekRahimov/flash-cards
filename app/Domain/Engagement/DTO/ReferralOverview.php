<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Models\Referral;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The inviter's view of "Invite a friend" (docs/04 A.12, `/invite`; docs/13 §7.1 "2C.9"): their code and link, the
 * activation rule, how many friends came and how many are counted, the milestones with what was reached, the next
 * one, and the latest invited learners (first names only — no other data of other people leaves the server).
 *
 * @phpstan-type Milestone array{friends: int, days: int, reached_at: CarbonImmutable|null, rewarded: bool}
 */
final readonly class ReferralOverview
{
    /**
     * @param  int  $invited  non-rejected referrals
     * @param  int  $activated  friends who have started (`activated` + `rewarded`)
     * @param  int  $counted  friends counted towards the milestones (`rewarded`)
     * @param  list<Milestone>  $milestones  the configured milestones, ascending; `reached_at` null until reached, `rewarded` once the grant was settled
     * @param  array{friends: int, remaining: int}|null  $next  the first milestone not reached yet; null after the last
     * @param  Collection<int, Referral>  $referrals  non-rejected, newest first, `referee` loaded
     */
    public function __construct(
        public string $code,
        public ?string $link,
        public int $activationDays,
        public int $activationReviewsPerDay,
        public int $activationWindowDays,
        public int $milestoneDays,
        public int $invited,
        public int $activated,
        public int $counted,
        public array $milestones,
        public ?array $next,
        public Collection $referrals,
    ) {}
}
