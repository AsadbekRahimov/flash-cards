<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Billing\Services\OfferViewTracker;
use App\Http\Requests\Twa\OfferViewedRequest;
use App\Models\Learner;
use Illuminate\Http\Response;

/**
 * POST /api/twa/billing/offer-viewed (throttle `twa-offer-viewed`): the Mini App showed a Pro offer (docs/04 A.8, docs/13 §5.1).
 * Always 204: a repeat of the day and a closed billing are not errors to the client, which never waits for this call.
 */
final class OfferViewedController
{
    public function __construct(private readonly OfferViewTracker $tracker) {}

    public function __invoke(OfferViewedRequest $request): Response
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');
        $this->tracker->record($learner, $request->placement());

        return response()->noContent();
    }
}
