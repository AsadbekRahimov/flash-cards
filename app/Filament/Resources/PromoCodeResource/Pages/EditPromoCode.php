<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoCodeResource\Pages;

use App\Filament\Resources\PromoCodeResource;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditPromoCode extends EditRecord
{
    protected static string $resource = PromoCodeResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
