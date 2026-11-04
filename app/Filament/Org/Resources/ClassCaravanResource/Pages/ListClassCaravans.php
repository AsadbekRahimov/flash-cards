<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources\ClassCaravanResource\Pages;

use App\Filament\Org\Resources\ClassCaravanResource;
use Filament\Resources\Pages\ListRecords;

class ListClassCaravans extends ListRecords
{
    protected static string $resource = ClassCaravanResource::class;
}
