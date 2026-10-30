<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Practice\Enums\PracticeMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/twa/practice/battles (docs/16 §7.3): `{friendship_id, mode, course, lesson_id, rematch_of}`.
 *
 * A rematch (`rematch_of`: my battle that is over) takes the friend, the format and the topic from that battle, so whatever is
 * sent for them - the TWA sends the mode, the course and the lesson along - is IGNORED, not checked: no 422 for a field the
 * server does not read. Without `rematch_of` the friendship, the format and the course are required.
 */
final class BattleInviteRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $rematch = $this->filled('rematch_of');

        return [
            'rematch_of' => ['nullable', 'integer', 'min:1', 'max:9223372036854775807'],
            'friendship_id' => $rematch ? ['nullable'] : ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'mode' => $rematch ? ['nullable'] : ['required', 'string', Rule::enum(PracticeMode::class)],
            'course' => $rematch ? ['nullable'] : ['required', 'string', 'max:64'],
            'lesson_id' => $rematch ? ['nullable'] : ['nullable', 'integer', 'min:1'],
        ];
    }

    public function friendshipId(): ?int
    {
        return $this->filled('friendship_id') ? $this->integer('friendship_id') : null;
    }

    /** The format asked for; with `rematch_of` a placeholder (the service takes the format of the battle that is repeated). */
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

    public function rematchOf(): ?int
    {
        return $this->filled('rematch_of') ? $this->integer('rematch_of') : null;
    }
}
