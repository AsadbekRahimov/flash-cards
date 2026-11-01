<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use Illuminate\Foundation\Http\FormRequest;

/** GET /api/twa/practice/battles/options?friendship_id=11&course=english-a1[&lesson_id=41] (docs/16 §7.3). */
final class BattleOptionsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'friendship_id' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'course' => ['required', 'string', 'max:64'],
            'lesson_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function friendshipId(): int
    {
        return $this->integer('friendship_id');
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
