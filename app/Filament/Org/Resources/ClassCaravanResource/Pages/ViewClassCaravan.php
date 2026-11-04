<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources\ClassCaravanResource\Pages;

use App\Filament\Org\Resources\ClassCaravanResource;
use App\Models\ClassCaravan;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewClassCaravan extends ViewRecord
{
    protected static string $resource = ClassCaravanResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel')
                ->label(__('org.caravans.actions.cancel'))
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(__('org.caravans.actions.cancel_confirm'))
                ->visible(fn (): bool => $this->caravan()->isActive())
                ->authorize(fn (): bool => ClassCaravanResource::can('cancel', $this->caravan()))
                ->action(function (): void {
                    ClassCaravanResource::cancel($this->caravan());
                    $this->caravan()->refresh();
                }),
        ];
    }

    private function caravan(): ClassCaravan
    {
        /** @var ClassCaravan $caravan */
        $caravan = $this->getRecord();

        return $caravan;
    }
}
