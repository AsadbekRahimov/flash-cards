<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Engagement\Services\ReferralService;
use App\Http\Resources\Twa\ReferralsResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Invite a friend" (docs/04 A.12, docs/13 §7.1): the learner's own referral overview. */
final class ReferralController
{
    public function __construct(private readonly ReferralService $referrals) {}

    /** GET /api/twa/referrals — generates the learner's code on first call. */
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        return (new ReferralsResource($this->referrals->overview($learner)))->response();
    }
}
