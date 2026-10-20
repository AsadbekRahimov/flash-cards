<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources\GroupResource\Pages;

use App\Filament\Org\Resources\GroupResource;
use Filament\Resources\Pages\ListRecords;

class ListGroups extends ListRecords
{
    protected static string $resource = GroupResource::class;
}
