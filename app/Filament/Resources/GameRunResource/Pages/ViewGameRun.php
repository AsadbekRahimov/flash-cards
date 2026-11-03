<?php

declare(strict_types=1);

namespace App\Filament\Resources\GameRunResource\Pages;

use App\Filament\Resources\GameRunResource;
use Filament\Resources\Pages\ViewRecord;

class ViewGameRun extends ViewRecord
{
    protected static string $resource = GameRunResource::class;

    public function getTitle(): string
    {
        return __('admin.game.runs.titles.view');
    }
}
