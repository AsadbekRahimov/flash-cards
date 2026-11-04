<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\CityShareService;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\GameCityResource;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The card of a city the learner has reached (docs/18 §12.9, docs/04 A.18): the mark that it was shared. */
final class GameCityController
{
    public function __construct(private readonly CityShareService $shares) {}

    /** POST /api/twa/game/cities/{stage}/shared - `{stage_id, shared_at}`; once, a repeat answers the same. */
    public function shared(Request $request, int $stage): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        try {
            return (new GameCityResource($this->shares->markShared($learner, $stage, CarbonImmutable::now())))->response();
        } catch (GameException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e->details);
        }
    }
}
