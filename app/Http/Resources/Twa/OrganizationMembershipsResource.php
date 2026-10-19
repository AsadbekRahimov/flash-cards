<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Models\OrganizationMember;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * GET /api/twa/me/organizations (and the answer of the DELETE): `{"organizations": [...]}`, the learner's
 * active memberships. `id` is `organization_members.id` — what `DELETE /me/organizations/{id}` takes.
 *
 * @property Collection<int, OrganizationMember> $resource
 */
final class OrganizationMembershipsResource extends TwaResource
{
    /**
     * @return array{organizations: list<array{id: int, name: string, seat_state: string, joined_via: string, joined_at: string, can_leave: bool}>}
     */
    public function toArray(Request $request): array
    {
        return [
            'organizations' => $this->resource
                ->map(static fn (OrganizationMember $member): array => [
                    'id' => $member->id,
                    'name' => $member->organization->name,
                    'seat_state' => $member->seat_state,
                    'joined_via' => $member->joined_via,
                    'joined_at' => $member->joined_at->toIso8601String(),
                    'can_leave' => $member->isLeavableByLearner(),
                ])
                ->values()
                ->all(),
        ];
    }
}
