<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Engagement\Exceptions\InvalidBoardCursor;
use App\Domain\Engagement\Exceptions\RegionNotSet;
use App\Domain\Engagement\Services\Scoring\AllTimeRatingService;
use App\Http\Requests\Twa\AllTimeRatingRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\AllTimeRatingResource;
use App\Http\Resources\Twa\LeagueBoardResource;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/** "All time" (docs/04 A.19a, docs/32 §5, §8.3, S10; the tables of a region - docs/35 §2.4): read-only for the learner. */
final class AllTimeRatingController
{
    public function __construct(private readonly AllTimeRatingService $rating) {}

    /**
     * GET /api/twa/league/all-time?category=&scope=&region=&after= — the table (top, the viewer's line and neighbours, the personal
     * total), or with `?after=` only the page after the cursor (`LeagueBoardResource`). 422: `invalid_cursor`, `region_not_set`.
     * Not `live`: `{enabled: false}`.
     */
    public function __invoke(AllTimeRatingRequest $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');
        $after = $request->cursor();

        try {
            if ($after !== null) {
                return (new LeagueBoardResource($this->rating->page($learner, CarbonImmutable::now(), $request->requestedCategory(), $request->regionChoice(), $after)))->response();
            }

            return (new AllTimeRatingResource($this->rating->overview($learner, CarbonImmutable::now(), $request->requestedCategory(), $request->regionChoice())))->response();
        } catch (InvalidBoardCursor $e) {
            return ApiErrorResource::respond(422, InvalidBoardCursor::ERROR_CODE, $e->getMessage());
        } catch (RegionNotSet $e) {
            return ApiErrorResource::respond(422, RegionNotSet::ERROR_CODE, $e->getMessage());
        }
    }
}
