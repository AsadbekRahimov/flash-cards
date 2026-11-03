<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Game\Enums\RunKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/twa/game/runs (docs/18 §12.8, docs/04 A.18). `kind = stop` (course + lesson) and `kind = daily` (the daily caravan: no
 * course, no lesson - the learner's active course); the boss and the boss of a class come with G2.4 and G2.9 and widen the list.
 */
final class StartGameRunRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $stop = $this->input('kind') !== RunKind::Daily->value;

        return [
            'kind' => ['required', 'string', Rule::in([RunKind::Stop->value, RunKind::Daily->value])],
            'course' => [$stop ? 'required' : 'nullable', 'string', 'max:64'],
            'lesson_id' => [$stop ? 'required' : 'nullable', 'integer', 'min:1', 'max:9223372036854775807'],
            'replace_active' => ['nullable', 'boolean'],
        ];
    }

    public function isDaily(): bool
    {
        return $this->string('kind')->toString() === RunKind::Daily->value;
    }

    public function course(): string
    {
        return $this->string('course')->toString();
    }

    public function lessonId(): int
    {
        return $this->integer('lesson_id');
    }

    public function replaceActive(): bool
    {
        return $this->boolean('replace_active');
    }
}
