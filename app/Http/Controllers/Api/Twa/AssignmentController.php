<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Organizations\Services\AssignmentService;
use App\Http\Resources\Twa\LearnerAssignmentsResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Assignments of the center" block of the Today screen (docs/04 A.9, docs/13 §1.4). */
final class AssignmentController
{
    public function __construct(private readonly AssignmentService $assignments) {}

    /** GET /api/twa/assignments */
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        return (new LearnerAssignmentsResource($this->assignments->forLearner($learner)))->response();
    }
}
