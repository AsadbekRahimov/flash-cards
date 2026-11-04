<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\Class\ClassCaravanOverview;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\ClassCaravanListResource;
use App\Http\Resources\Twa\ClassCaravanResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/twa/game/class and /game/class/{id} (docs/18 §12.8, docs/04 A.18): the caravans of the learner's classes. */
final class ClassCaravanController
{
    public function __construct(private readonly ClassCaravanOverview $caravans) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        try {
            return (new ClassCaravanListResource($this->caravans->list($learner)))->response();
        } catch (GameException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e->details);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        try {
            return (new ClassCaravanResource($this->caravans->show($learner, $id)))->response();
        } catch (GameException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e->details);
        }
    }
}
