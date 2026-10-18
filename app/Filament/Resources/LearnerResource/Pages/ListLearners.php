<?php

declare(strict_types=1);

namespace App\Filament\Resources\LearnerResource\Pages;

use App\Filament\Resources\LearnerResource;
use Filament\Resources\Pages\ListRecords;

class ListLearners extends ListRecords
{
    protected static string $resource = LearnerResource::class;
}
