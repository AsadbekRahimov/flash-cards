<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrganizationResource\Pages;

use App\Filament\Resources\OrganizationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOrganization extends CreateRecord
{
    protected static string $resource = OrganizationResource::class;

    public function getTitle(): string
    {
        return __('admin.organizations.titles.create');
    }
}
