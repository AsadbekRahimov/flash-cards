<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Events;

/**
 * An active membership ended (`left` / `removed`), docs/13 §2.3. Raised after commit.
 * `wasSeated` is the hook of the B2B -> B2C bridge (2B.7): a learner who lost a seat and has
 * no personal live subscription gets `OfferService::createPostOrgDiscount` from a listener.
 */
final readonly class OrganizationMemberLeft
{
    public function __construct(
        public int $organizationId,
        public int $learnerId,
        public int $memberId,
        public string $status,
        public bool $wasSeated,
        public ?int $byUserId,
    ) {}
}
