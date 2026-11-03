<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\MapService;
use App\Http\Requests\Twa\GameMapRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\GameMapResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;

/** GET /api/twa/game/map (docs/18 §12.8, docs/04 A.18): the map of a course for the learner. */
final class GameMapController
{
    public function __construct(private readonly MapService $maps) {}

    public function show(GameMapRequest $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        try {
            $map = $this->maps->map($learner, $request->course());
        } catch (GameException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e->details);
        }

        return (new GameMapResource($map))->response();
    }
}
