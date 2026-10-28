<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProviderTransactionResource\Pages;

use App\Filament\Resources\ProviderTransactionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewProviderTransaction extends ViewRecord
{
    protected static string $resource = ProviderTransactionResource::class;

    public function getTitle(): string
    {
        return __('admin.provider_transactions.titles.view');
    }
}
