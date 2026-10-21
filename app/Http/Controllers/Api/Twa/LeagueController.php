<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Engagement\Services\LeagueService;
use App\Http\Resources\Twa\LeagueResource;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Leagues (docs/04 A.13, docs/13 §7.3): the learner's group this week. */
final class LeagueController
{
    public function __construct(private readonly LeagueService $leagues) {}

    /** GET /api/twa/league — read-only: placement happens on the first graded card of the week. */
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        return (new LeagueResource($this->leagues->overview($learner, CarbonImmutable::now())))->response();
    }
}
