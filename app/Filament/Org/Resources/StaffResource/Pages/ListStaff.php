<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources\StaffResource\Pages;

use App\Filament\Org\Resources\StaffResource;
use Filament\Resources\Pages\ListRecords;

class ListStaff extends ListRecords
{
    protected static string $resource = StaffResource::class;
}
