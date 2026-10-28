<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseResource\Pages;

use App\Domain\Content\Services\CourseCatalogService;
use App\Filament\Resources\CourseResource;
use App\Filament\Resources\CourseResource\Support\CourseTransitionActions;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditCourse extends EditRecord
{
    protected static string $resource = CourseResource::class;

    public function getTitle(): string
    {
        return __('admin.courses.titles.edit');
    }

    /** Title, description, level, visibility and order are shown in the TWA catalog (cached). */
    protected function afterSave(): void
    {
        app(CourseCatalogService::class)->forgetPublished();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return CourseTransitionActions::all();
    }
}
