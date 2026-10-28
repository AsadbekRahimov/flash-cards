<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrganizationResource\Pages;

use App\Domain\Administration\Services\OrganizationAdminService;
use App\Filament\Resources\OrganizationResource;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditOrganization extends EditRecord
{
    protected static string $resource = OrganizationResource::class;

    public function getTitle(): string
    {
        return __('admin.organizations.titles.edit');
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()->authorizationTooltip()->authorizationNotification()];
    }

    /**
     * A status change (suspend / activate) is audited by OrganizationAdminService.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Organization $record */
        /** @var User $user */
        $user = auth()->user();

        return app(OrganizationAdminService::class)->update($record, $data, $user, request()->ip());
    }
}
