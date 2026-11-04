<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Game\Enums\RunKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/twa/game/runs (docs/18 §12.8, docs/04 A.18). `kind = stop` (course + lesson) and `kind = daily` (the daily caravan: no
 * course, no lesson - the learner's active course); `kind = boss` (course + stage_id: the boss of the
 * city, G2.4); `kind = class_boss` (class_caravan_id: the boss of the class, no course - the caravan's, G2.9a).
 */
final class StartGameRunRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $kind = $this->input('kind');
        $daily = $kind === RunKind::Daily->value;
        $boss = $kind === RunKind::Boss->value;
        $classBoss = $kind === RunKind::ClassBoss->value;

        return [
            'kind' => ['required', 'string', Rule::in([RunKind::Stop->value, RunKind::Daily->value, RunKind::Boss->value, RunKind::ClassBoss->value])],
            'course' => [$daily || $classBoss ? 'nullable' : 'required', 'string', 'max:64'],
            'lesson_id' => [$daily || $boss || $classBoss ? 'nullable' : 'required', 'integer', 'min:1', 'max:9223372036854775807'],
            'stage_id' => [$boss ? 'required' : 'nullable', 'integer', 'min:1', 'max:9223372036854775807'],
            'class_caravan_id' => [$classBoss ? 'required' : 'nullable', 'integer', 'min:1', 'max:9223372036854775807'],
            'replace_active' => ['nullable', 'boolean'],
        ];
    }

    public function isDaily(): bool
    {
        return $this->string('kind')->toString() === RunKind::Daily->value;
    }

    public function isBoss(): bool
    {
        return $this->string('kind')->toString() === RunKind::Boss->value;
    }

    public function isClassBoss(): bool
    {
        return $this->string('kind')->toString() === RunKind::ClassBoss->value;
    }

    public function classCaravanId(): int
    {
        return $this->integer('class_caravan_id');
    }

    public function course(): string
    {
        return $this->string('course')->toString();
    }

    public function lessonId(): int
    {
        return $this->integer('lesson_id');
    }

    public function stageId(): int
    {
        return $this->integer('stage_id');
    }

    public function replaceActive(): bool
    {
        return $this->boolean('replace_active');
    }
}
