<?php

declare(strict_types=1);

namespace App\Filament\Resources\SubscriptionResource\Pages;

use App\Filament\Resources\SubscriptionResource;
use App\Filament\Resources\SubscriptionResource\Support\SubscriptionActions;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListSubscriptions extends ListRecords
{
    protected static string $resource = SubscriptionResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [SubscriptionActions::grantPro()];
    }
}
