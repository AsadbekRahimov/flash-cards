<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Models\TrainingSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/twa/training/sessions. Access is the `TwaAuth` middleware; whether the lesson
 * exists, is published and fits the mode is a service rule (`TrainingSessionService::openPersonal`); the round of hard words
 * (`hard_review`, docs/06 §14) opens through `HardRoundService::open` and takes no lesson; the review of a lesson (`lesson_review`, docs/06 §15)
 * through `LessonReviewService::open` and needs one. `source = finish`: opened from the end of a session; `plan`: from the day plan of "Today".
 */
final class OpenTrainingSessionRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::in([TrainingSession::MODE_LESSON, TrainingSession::MODE_DAILY_REVIEW, TrainingSession::MODE_HARD_REVIEW, TrainingSession::MODE_LESSON_REVIEW])],
            'lesson_id' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', 'string', Rule::in(['self', 'picker', 'reminder', 'reminder_quiz', 'onboarding', 'assignment', 'finish', 'plan'])],
        ];
    }

    public function lessonId(): ?int
    {
        return $this->filled('lesson_id') ? $this->integer('lesson_id') : null;
    }

    public function source(): string
    {
        return $this->filled('source') ? $this->string('source')->toString() : 'self';
    }
}
