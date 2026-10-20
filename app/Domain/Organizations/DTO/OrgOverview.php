<?php

declare(strict_types=1);

namespace App\Domain\Organizations\DTO;

use Carbon\CarbonImmutable;

/**
 * Dashboard numbers of the `/org` cabinet (docs/13 §6.3) for ONE staff user: scoped to what that
 * user sees (owner — the organization; teacher — their groups). `subscription*` is `null` for a
 * teacher (billing is the owner's).
 */
final readonly class OrgOverview
{
    public function __construct(
        public SeatsSummary $seats,
        public int $groups,
        public int $activeMembers,
        public int $activeLearners7d,
        public int $reviews7d,
        public int $newWords7d,
        public ?string $subscriptionTier,
        public ?string $subscriptionStatus,
        public ?CarbonImmutable $subscriptionEndsAt,
    ) {}
}
