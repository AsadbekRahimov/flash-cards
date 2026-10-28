<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExamSessionResource\Pages;

use App\Filament\Resources\ExamSessionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewExamSession extends ViewRecord
{
    protected static string $resource = ExamSessionResource::class;

    public function getTitle(): string
    {
        return __('admin.exams.titles.view');
    }
}
