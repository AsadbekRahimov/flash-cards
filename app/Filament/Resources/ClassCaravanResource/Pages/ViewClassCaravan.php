<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClassCaravanResource\Pages;

use App\Filament\Resources\ClassCaravanResource;
use App\Models\ClassCaravan;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewClassCaravan extends ViewRecord
{
    protected static string $resource = ClassCaravanResource::class;

    public function getTitle(): string
    {
        return __('admin.game.caravans.titles.view');
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            ClassCaravanResource::cancelAction()
                ->record(fn (): ClassCaravan => $this->caravan())
                ->after(fn () => $this->caravan()->refresh()),
        ];
    }

    private function caravan(): ClassCaravan
    {
        /** @var ClassCaravan $caravan */
        $caravan = $this->getRecord();

        return $caravan;
    }
}
