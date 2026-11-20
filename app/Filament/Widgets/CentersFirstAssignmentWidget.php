<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\DashboardOverview;
use App\Filament\Resources\OrganizationResource;

/** «Центры: до первого задания»: the active centers still waiting for a completed assignment, then the latest that got there. */
class CentersFirstAssignmentWidget extends DashboardBlockWidget
{
    protected string $view = 'filament.widgets.dashboard.centers';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $centers = app(DashboardOverview::class)->centers();

        return [
            'centers' => $centers,
            'urls' => array_combine(
                array_column($centers['rows'], 'id'),
                array_map(static fn (array $row): string => OrganizationResource::getUrl('edit', ['record' => $row['id']]), $centers['rows']),
            ),
        ];
    }
}
