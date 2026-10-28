<?php

declare(strict_types=1);

namespace App\Filament\Resources\StageResource\Pages;

use App\Filament\Resources\StageResource;
use Filament\Resources\Pages\EditRecord;

class EditStage extends EditRecord
{
    protected static string $resource = StageResource::class;

    public function getTitle(): string
    {
        return __('admin.stages.titles.edit');
    }
}
