<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Engagement\Exceptions\InvalidBoardCursor;
use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Http\Requests\Twa\MonthlyRatingRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\LeagueBoardResource;
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

    /**
     * GET /api/twa/league/month — the top list, the viewer's own (fresh) line, the prizes, the previous month; a v2 month also the
     * neighbours and the table of `?category=` (docs/32 §8.3). With `?after=` - only the page of that table after the cursor
     * (`LeagueBoardResource`); a cursor that is not one of this table → 422 `invalid_cursor`.
     */
    public function show(MonthlyRatingRequest $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');
        $after = $request->cursor();

        if ($after !== null) {
            try {
                return (new LeagueBoardResource($this->rating->boardPage($learner, CarbonImmutable::now(), $request->requestedCategory(), $after)))->response();
            } catch (InvalidBoardCursor $e) {
                return ApiErrorResource::respond(422, InvalidBoardCursor::ERROR_CODE, $e->getMessage());
            }
        }

        return (new MonthlyRatingResource($this->rating->overview($learner, CarbonImmutable::now(), $request->requestedCategory())))->response();
    }

    /** GET /api/twa/league/month/me — the viewer's own line only (the finish screen of a session). */
    public function me(Request $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        return (new MonthlyRatingMeResource($this->rating->meView($learner, CarbonImmutable::now())))->response();
    }
}
