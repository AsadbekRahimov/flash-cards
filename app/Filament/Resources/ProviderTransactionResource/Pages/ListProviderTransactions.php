<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProviderTransactionResource\Pages;

use App\Filament\Resources\ProviderTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListProviderTransactions extends ListRecords
{
    protected static string $resource = ProviderTransactionResource::class;
}
