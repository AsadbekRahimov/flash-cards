<?php

declare(strict_types=1);

namespace App\Filament\Org\Widgets;

use App\Domain\Organizations\DTO\OrgChecklist;
use App\Domain\Organizations\Services\OrgOnboardingChecklist;
use App\Domain\Organizations\Services\OrgTenantScope;
use App\Filament\Org\Resources\GroupResource;
use App\Filament\Org\Resources\InviteResource;
use App\Filament\Org\Resources\MemberResource;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * "First steps" of a new center on the `/org` dashboard (audit 2026-11-10 §4 P1): five steps with a tick, and for every step still
 * to do a short hint and a link to the section where it is done. The list is computed in three aggregate queries
 * ({@see OrgOnboardingChecklist}) and disappears once every step is done. Visible to the owner and to a teacher; a link is shown only
 * where the viewer may open the section (the group is linked by the owner alone), the step itself stays on the list.
 */
class OrgChecklistWidget extends Widget
{
    protected static ?int $sort = 0;

    protected string $view = 'filament.org.widgets.checklist';

    protected int|string|array $columnSpan = 'full';

    // Not lazy: the list is three cheap queries and a placeholder would add a round trip; no polling either (docs/20 §1.11).
    protected static bool $isLazy = false;

    /** @return array{checklist: OrgChecklist|null, links: array<string, string|null>} */
    protected function getViewData(): array
    {
        $user = Filament::auth()->user();
        $tenant = Filament::getTenant();

        if (! $user instanceof User || ! $tenant instanceof Organization) {
            return ['checklist' => null, 'links' => []];
        }

        $checklist = app(OrgOnboardingChecklist::class)->build($user, $tenant);

        if ($checklist->isComplete()) {
            return ['checklist' => null, 'links' => []];
        }

        $groups = GroupResource::canViewAny() ? GroupResource::getUrl('index') : null;

        return [
            'checklist' => $checklist,
            'links' => [
                'telegram' => Filament::getProfileUrl(),
                'group' => $this->isOwner($user, $tenant) ? $groups : null,
                'course' => $groups,
                'learners' => MemberResource::canViewAny()
                    ? MemberResource::getUrl('index')
                    : (InviteResource::canViewAny() ? InviteResource::getUrl('index') : null),
                'training' => null,
            ],
        ];
    }

    private function isOwner(User $user, Organization $tenant): bool
    {
        return app(OrgTenantScope::class)->isOwner($user, $tenant);
    }
}
