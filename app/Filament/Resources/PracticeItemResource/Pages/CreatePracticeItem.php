<?php

declare(strict_types=1);

namespace App\Filament\Resources\PracticeItemResource\Pages;

use App\Domain\Practice\Exceptions\PracticeContentException;
use App\Domain\Practice\Exceptions\PracticeItemStateException;
use App\Domain\Practice\Services\PracticeItemService;
use App\Filament\Resources\PracticeItemResource;
use App\Filament\Resources\PracticeItemResource\Support\PracticeItemActions;
use App\Filament\Resources\PracticeItemResource\Support\PracticeItemFormMapper;
use App\Models\Course;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePracticeItem extends CreateRecord
{
    protected static string $resource = PracticeItemResource::class;

    public function getTitle(): string
    {
        return __('admin.practice_items.titles.create');
    }

    /**
     * A new task is made with its first version - a draft - by PracticeItemService, which checks the text (docs/16 §4.5),
     * takes the lock of the course and owns the status. A text it refuses stays in the form, with the reason above it.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();
        $courseId = $data['course_id'] ?? null;
        $course = is_int($courseId) || (is_string($courseId) && ctype_digit($courseId)) ? Course::query()->find((int) $courseId) : null;

        try {
            if ($course === null) {
                throw new PracticeContentException(PracticeContentException::COURSE_INVALID, 'course', 'The course does not exist.');
            }

            $saved = app(PracticeItemService::class)->save(null, $course, PracticeItemFormMapper::toData($data), $user);
        } catch (PracticeContentException|PracticeItemStateException $e) {
            PracticeItemActions::refused($e);

            $this->halt();

            throw $e;
        }

        return $saved->item;
    }
}
