<?php

declare(strict_types=1);

namespace App\Domain\Organizations\DTO;

use Carbon\CarbonImmutable;

/**
 * Dashboard numbers of the `/org` cabinet (docs/13 §6.3) for ONE staff user: scoped to what that
 * user sees (owner — the organization; teacher — their groups). `subscription*` is `null` for a
 * teacher (billing is the owner's).
 *
 * `toArray()` / `fromArray()` are the form it takes in the cache (scalars only, docs/19 §3 rule 2): the date is an ISO 8601 string.
 *
 * @phpstan-type OrgOverviewArray array{seats: array{limit: int|null, seated: int, waitlisted: int, sponsoring: bool}, groups: int, active_members: int, active_learners_7d: int, reviews_7d: int, new_words_7d: int, subscription_tier: string|null, subscription_status: string|null, subscription_ends_at: string|null}
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

    /** @return OrgOverviewArray */
    public function toArray(): array
    {
        return [
            'seats' => $this->seats->toArray(),
            'groups' => $this->groups,
            'active_members' => $this->activeMembers,
            'active_learners_7d' => $this->activeLearners7d,
            'reviews_7d' => $this->reviews7d,
            'new_words_7d' => $this->newWords7d,
            'subscription_tier' => $this->subscriptionTier,
            'subscription_status' => $this->subscriptionStatus,
            'subscription_ends_at' => $this->subscriptionEndsAt?->toIso8601String(),
        ];
    }

    /** @param  OrgOverviewArray  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            seats: SeatsSummary::fromArray($data['seats']),
            groups: $data['groups'],
            activeMembers: $data['active_members'],
            activeLearners7d: $data['active_learners_7d'],
            reviews7d: $data['reviews_7d'],
            newWords7d: $data['new_words_7d'],
            subscriptionTier: $data['subscription_tier'],
            subscriptionStatus: $data['subscription_status'],
            subscriptionEndsAt: $data['subscription_ends_at'] === null ? null : CarbonImmutable::parse($data['subscription_ends_at']),
        );
    }
}
