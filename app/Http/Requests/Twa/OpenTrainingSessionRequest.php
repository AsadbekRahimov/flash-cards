<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Models\TrainingSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/twa/training/sessions. Access is the `TwaAuth` middleware; whether the lesson
 * exists, is published and fits the mode is a service rule (`TrainingSessionService::openPersonal`).
 */
final class OpenTrainingSessionRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::in([TrainingSession::MODE_LESSON, TrainingSession::MODE_DAILY_REVIEW])],
            'lesson_id' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', 'string', Rule::in(['self', 'picker', 'reminder', 'onboarding', 'assignment'])],
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
