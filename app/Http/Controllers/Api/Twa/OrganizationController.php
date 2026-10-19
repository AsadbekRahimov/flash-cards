<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Organizations\Exceptions\OrganizationMembershipException;
use App\Domain\Organizations\Services\LearnerOrganizationsService;
use App\Http\Requests\Twa\LeaveOrganizationRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\OrganizationMembershipsResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The learner's own centers: the list and "Leave the center" (docs/04 A.11, docs/13 §6.2). */
final class OrganizationController
{
    public function __construct(private readonly LearnerOrganizationsService $organizations) {}

    /** GET /api/twa/me/organizations */
    public function index(Request $request): JsonResponse
    {
        return (new OrganizationMembershipsResource($this->organizations->memberships($this->learner($request))))->response();
    }

    /** DELETE /api/twa/me/organizations/{member} (throttle `twa-organizations`) — answers with the refreshed list. */
    public function leave(LeaveOrganizationRequest $request): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $this->organizations->leave($learner, $request->memberId());
        } catch (OrganizationMembershipException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage());
        }

        return (new OrganizationMembershipsResource($this->organizations->memberships($learner)))->response();
    }

    private function learner(Request $request): Learner
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        return $learner;
    }
}
