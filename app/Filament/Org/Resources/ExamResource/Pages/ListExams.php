<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources\ExamResource\Pages;

use App\Filament\Org\Resources\ExamResource;
use Filament\Resources\Pages\ListRecords;

class ListExams extends ListRecords
{
    protected static string $resource = ExamResource::class;
}
