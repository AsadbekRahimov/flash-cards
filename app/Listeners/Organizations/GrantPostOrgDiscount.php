<?php

declare(strict_types=1);

namespace App\Listeners\Organizations;

use App\Domain\Billing\Services\OfferService;
use App\Domain\Organizations\Events\OrganizationMemberLeft;
use App\Domain\Telegram\Services\OfferMessenger;
use App\Models\Learner;
use App\Models\Organization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * B2B -> B2C bridge (docs/13 §6.5): a learner who lost a SEATED place gets the one-time `post_org_discount`
 * and a DM about it. Waitlisted members lost nothing. Queued after commit; the offer service creates at most one
 * open offer (a repeated event creates none), and a retry after a failed DM finds the offer again and finishes
 * the message — the DM itself is sent once per offer. Auto-discovered (`app/Listeners`).
 */
final class GrantPostOrgDiscount implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(
        private readonly OfferService $offers,
        private readonly OfferMessenger $messenger,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(OrganizationMemberLeft $event): void
    {
        if (! $event->wasSeated) {
            return;
        }

        // A soft-deleted learner / a deleted center: nothing to offer.
        $learner = Learner::query()->find($event->learnerId);
        $organization = Organization::query()->find($event->organizationId);

        if ($learner === null || $organization === null) {
            return;
        }

        $offer = $this->offers->createPostOrgDiscount($learner, $organization)
            ?? $this->offers->unannouncedPostOrgDiscount($learner, $organization);

        if ($offer !== null) {
            $this->messenger->notifyPostOrgDiscount($offer);
        }
    }
}
