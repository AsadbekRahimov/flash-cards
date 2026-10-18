<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Twa\Services\MePayloadBuilder;
use App\Http\Resources\Twa\MeResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController
{
    public function __construct(private readonly MePayloadBuilder $payload) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        return (new MeResource($this->payload->build($learner)))->response();
    }
}
