<?php

declare(strict_types=1);

namespace App\Filament\Resources\LearnerResource\Pages;

use App\Filament\Resources\LearnerResource;
use App\Filament\Resources\LearnerResource\Support\PracticePilotAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewLearner extends ViewRecord
{
    protected static string $resource = LearnerResource::class;

    public function getTitle(): string
    {
        return __('admin.learners.titles.view');
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [PracticePilotAction::include(), PracticePilotAction::exclude()];
    }
}
