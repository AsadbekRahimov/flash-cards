<?php

declare(strict_types=1);

namespace App\Filament\Resources\PracticeItemResource\Pages;

use App\Filament\Pages\ImportPracticeItems;
use App\Filament\Resources\PracticeItemResource;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListPracticeItems extends ListRecords
{
    protected static string $resource = PracticeItemResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(__('admin.practice_items.actions.create')),
            Action::make('import')
                ->label(__('admin.practice_items.actions.import'))
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->url(fn (): string => ImportPracticeItems::getUrl())
                ->visible(fn (): bool => ImportPracticeItems::canAccess()),
        ];
    }
}
