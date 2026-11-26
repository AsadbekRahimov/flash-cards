<?php

declare(strict_types=1);

namespace App\Filament\Resources\CupResource\Pages;

use App\Filament\Resources\CupResource;
use Filament\Resources\Pages\ListRecords;

class ListCups extends ListRecords
{
    protected static string $resource = CupResource::class;
}
