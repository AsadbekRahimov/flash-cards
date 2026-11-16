<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Http\Resources\Twa\MonthlyRatingMeResource;
use App\Http\Resources\Twa\MonthlyRatingResource;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The monthly rating with prizes (docs/04 A.19, docs/13 §7.6): read-only for the learner. */
final class MonthlyRatingController
{
    public function __construct(private readonly MonthlyRatingService $rating) {}

    /** GET /api/twa/league/month — the top list, the viewer's own (fresh) line, the prizes, the previous month. */
    public function show(Request $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        return (new MonthlyRatingResource($this->rating->overview($learner, CarbonImmutable::now())))->response();
    }

    /** GET /api/twa/league/month/me — the viewer's own line only (the finish screen of a session). */
    public function me(Request $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        return (new MonthlyRatingMeResource($this->rating->meView($learner, CarbonImmutable::now())))->response();
    }
}
