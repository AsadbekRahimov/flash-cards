<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseResource\Pages;

use App\Domain\Content\Exceptions\CourseKindException;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Content\Services\CourseKindGuard;
use App\Filament\Resources\CourseResource;
use App\Filament\Resources\CourseResource\Support\CourseTransitionActions;
use App\Models\Course;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditCourse extends EditRecord
{
    protected static string $resource = CourseResource::class;

    public function getTitle(): string
    {
        return __('admin.courses.titles.edit');
    }

    /**
     * The kind of the course (`exam_tag` «phrases» or not) may not cross the line while it holds words of the other kind (docs/35 §3.3;
     * the rule is {@see CourseKindGuard}): the error stands under the field, nothing is saved.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Course $course */
        $course = $this->getRecord();
        $tag = $data['exam_tag'] ?? null;
        try {
            app(CourseKindGuard::class)->assertExamTagChange($course, is_string($tag) && $tag !== '' ? $tag : null);
        } catch (CourseKindException $e) {
            throw ValidationException::withMessages([
                'data.exam_tag' => __($e->toPhrases ? 'admin.courses.errors.kind_to_phrases' : 'admin.courses.errors.kind_from_phrases'),
            ]);
        }

        return $data;
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
