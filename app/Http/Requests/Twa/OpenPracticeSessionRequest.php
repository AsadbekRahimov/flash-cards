<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Practice\Enums\PracticeMode;
use App\Models\PracticeSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/twa/practice/sessions (docs/16 §7.2). With `retry_of` the mode, the course and the lesson are taken from the
 * finished session it names, and whatever is sent in their place is ignored - so they are not required then.
 */
final class OpenPracticeSessionRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'mode' => ['required_without:retry_of', 'nullable', 'string', Rule::enum(PracticeMode::class)],
            'course' => ['required_without:retry_of', 'nullable', 'string', 'max:64'],
            'lesson_id' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', 'string', Rule::in(PracticeSession::SOURCES)],
            'replace_active' => ['nullable', 'boolean'],
            'retry_of' => ['nullable', 'integer', 'min:1', 'max:9223372036854775807'],
        ];
    }

    /** The mode asked for; with `retry_of` and no mode a placeholder (the service takes the mode of the retried session). */
    public function mode(): PracticeMode
    {
        return PracticeMode::tryFrom($this->string('mode')->toString()) ?? PracticeMode::Pairs;
    }

    public function course(): string
    {
        return $this->string('course')->toString();
    }

    public function lessonId(): ?int
    {
        return $this->filled('lesson_id') ? $this->integer('lesson_id') : null;
    }

    public function source(): string
    {
        return $this->filled('source') ? $this->string('source')->toString() : PracticeSession::SOURCE_HUB;
    }

    public function replaceActive(): bool
    {
        return $this->boolean('replace_active');
    }

    public function retryOf(): ?int
    {
        return $this->filled('retry_of') ? $this->integer('retry_of') : null;
    }
}
