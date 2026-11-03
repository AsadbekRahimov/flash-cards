<?php

declare(strict_types=1);

namespace App\Filament\Resources\GameItemResource\Pages;

use App\Filament\Resources\GameItemResource;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListGameItems extends ListRecords
{
    protected static string $resource = GameItemResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
