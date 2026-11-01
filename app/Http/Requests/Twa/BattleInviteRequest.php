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
 * sent for them - the TWA sends the mode, the course and the lesson along - is IGNORED: not validated (no 422 for a field the
 * server does not read) and not even looked at. Without `rematch_of` the friendship, the format and the course are required.
 */
final class BattleInviteRequest extends FormRequest
{
    private const MAX_ID = '9223372036854775807';

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        if ($this->isRematch()) {
            return ['rematch_of' => ['integer', 'min:1', 'max:'.self::MAX_ID]];
        }

        return [
            'rematch_of' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_ID],
            'friendship_id' => ['required', 'integer', 'min:1', 'max:'.self::MAX_ID],
            'mode' => ['required', 'string', Rule::enum(PracticeMode::class)],
            'course' => ['required', 'string', 'max:64'],
            'lesson_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function friendshipId(): ?int
    {
        return ! $this->isRematch() && $this->filled('friendship_id') ? $this->integer('friendship_id') : null;
    }

    /** The format asked for; with `rematch_of` a placeholder (the service takes the format of the battle that is repeated). */
    public function mode(): PracticeMode
    {
        return $this->isRematch() ? PracticeMode::Pairs : (PracticeMode::tryFrom($this->string('mode')->toString()) ?? PracticeMode::Pairs);
    }

    public function course(): string
    {
        return $this->isRematch() ? '' : $this->string('course')->toString();
    }

    public function lessonId(): ?int
    {
        return ! $this->isRematch() && $this->filled('lesson_id') ? $this->integer('lesson_id') : null;
    }

    public function rematchOf(): ?int
    {
        return $this->isRematch() ? $this->integer('rematch_of') : null;
    }

    private function isRematch(): bool
    {
        return $this->filled('rematch_of');
    }
}
