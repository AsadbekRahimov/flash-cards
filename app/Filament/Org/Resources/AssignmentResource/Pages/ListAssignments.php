<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources\AssignmentResource\Pages;

use App\Filament\Org\Resources\AssignmentResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAssignments extends ListRecords
{
    protected static string $resource = AssignmentResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('org.assignments.actions.create'))];
    }
}
