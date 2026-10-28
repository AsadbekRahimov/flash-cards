<?php

declare(strict_types=1);

namespace App\Filament\Resources\LearnerResource\Pages;

use App\Filament\Resources\LearnerResource;
use Filament\Resources\Pages\ViewRecord;

class ViewLearner extends ViewRecord
{
    protected static string $resource = LearnerResource::class;

    public function getTitle(): string
    {
        return __('admin.learners.titles.view');
    }
}
