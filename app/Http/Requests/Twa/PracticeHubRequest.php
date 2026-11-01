<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use Illuminate\Foundation\Http\FormRequest;

/** GET /api/twa/practice/hub?course={slug}[&lesson_id={id}]. Access is the `TwaAuth` middleware and PracticeAccess. */
final class PracticeHubRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'course' => ['required', 'string', 'max:64'],
            'lesson_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function course(): string
    {
        return $this->string('course')->toString();
    }

    public function lessonId(): ?int
    {
        return $this->filled('lesson_id') ? $this->integer('lesson_id') : null;
    }
}
