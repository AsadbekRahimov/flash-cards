<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Learners\Services\GroupMembershipService;
use App\Domain\Learning\Services\LearnerStatsService;
use App\Http\Resources\Twa\MeResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController
{
    public function __construct(
        private readonly LearnerStatsService $stats,
        private readonly GroupMembershipService $memberships,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        return (new MeResource([
            'learner' => $learner,
            'groups' => $this->memberships->activeGroups($learner),
            'stats' => $this->stats->forLearner($learner),
        ]))->response();
    }
}
