<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa\Cup;

use App\Domain\Practice\Enums\PracticeMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/twa/cups/{id}/duels (docs/04 A.23d): `{opponent_participant_id?, public, mode = pairs, request_id}`. Only the shape is
 * checked here; who may be challenged, whether the mode is played in a duel (422 `cup_duel_mode_invalid`) and the limits are the core's.
 * The opponent is a participant id (the table gives no other id); a missing one is an open challenge to anybody.
 */
final class CupDuelChallengeRequest extends FormRequest
{
    private const MAX_ID = '9223372036854775807';

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'opponent_participant_id' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_ID],
            'public' => ['required', 'boolean'],
            'mode' => ['nullable', 'string', Rule::enum(PracticeMode::class)],
            'request_id' => ['required', 'string', 'uuid'],
        ];
    }

    public function opponentParticipantId(): ?int
    {
        return $this->filled('opponent_participant_id') ? $this->integer('opponent_participant_id') : null;
    }

    public function isPublic(): bool
    {
        return $this->boolean('public');
    }

    public function mode(): PracticeMode
    {
        return PracticeMode::tryFrom($this->string('mode')->toString()) ?? PracticeMode::Pairs;
    }

    /** A UUID in lower case (PostgreSQL stores it that way, SQLite compares strings). */
    public function requestId(): string
    {
        return strtolower($this->string('request_id')->toString());
    }
}
