<?php

declare(strict_types=1);

namespace App\Filament\Resources\PollResource\Pages;

use App\Filament\Resources\PollResource;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListPolls extends ListRecords
{
    protected static string $resource = PollResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label(__('admin.polls.actions.create'))];
    }
}
