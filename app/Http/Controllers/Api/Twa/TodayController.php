<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Learners\Services\TodayService;
use App\Http\Resources\Twa\TodayResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TodayController
{
    public function __construct(private readonly TodayService $today) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        return (new TodayResource($this->today->summary($learner)))->response();
    }
}
