<?php

declare(strict_types=1);

namespace App\Filament\Resources\GameRunResource\Pages;

use App\Filament\Resources\GameRunResource;
use Filament\Resources\Pages\ListRecords;

class ListGameRuns extends ListRecords
{
    protected static string $resource = GameRunResource::class;
}
