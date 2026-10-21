<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Engagement\Enums\AchievementKey;
use App\Domain\Engagement\Services\AchievementService;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\AchievementsResource;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Achievements (docs/04 A.13, docs/13 §7.4): the list with progress, and the "shared" mark. */
final class AchievementController
{
    public function __construct(private readonly AchievementService $achievements) {}

    /** GET /api/twa/achievements */
    public function index(Request $request): JsonResponse
    {
        return $this->overview($this->learner($request));
    }

    /** POST /api/twa/achievements/{key}/shared — the learner opened the share dialog; idempotent, 404 for a key not unlocked. */
    public function shared(Request $request, string $key): JsonResponse
    {
        $learner = $this->learner($request);
        $enum = AchievementKey::tryFrom($key);

        if ($enum === null || ! $this->achievements->markShared($learner, $enum, CarbonImmutable::now())) {
            return ApiErrorResource::respond(404, 'achievement_not_found', 'Achievement not found.');
        }

        return $this->overview($learner);
    }

    private function overview(Learner $learner): JsonResponse
    {
        return (new AchievementsResource($this->achievements->overview($learner)))->response();
    }

    private function learner(Request $request): Learner
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        return $learner;
    }
}
