<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Game\Enums\RunKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/twa/game/runs (docs/18 §12.8, docs/04 A.18). Only `kind = stop` for now: the boss, the daily caravan and the boss of a
 * class come with G2.4, G2.3 and G2.9 and widen the list of kinds (and the keys they need) then.
 */
final class StartGameRunRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in([RunKind::Stop->value])],
            'course' => ['required', 'string', 'max:64'],
            'lesson_id' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'replace_active' => ['nullable', 'boolean'],
        ];
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
