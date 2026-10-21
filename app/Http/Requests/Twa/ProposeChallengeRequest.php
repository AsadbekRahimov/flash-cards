<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Engagement\Enums\ChallengeMetric;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/twa/challenges: the friend is addressed by the id of the friendship (never by a learner id). */
final class ProposeChallengeRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'friendship_id' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'metric' => ['required', 'string', Rule::enum(ChallengeMetric::class)],
        ];
    }

    public function friendshipId(): int
    {
        return $this->integer('friendship_id');
    }

    public function metric(): string
    {
        return $this->string('metric')->toString();
    }
}
