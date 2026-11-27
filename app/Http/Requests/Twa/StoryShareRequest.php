<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Engagement\Enums\ShareKind;
use App\Domain\Engagement\Services\ShareMessageBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/twa/share/story (docs/40 §2.4, docs/04 A.26): `kind` - `streak` or `achievement` (the invitations and the game have no
 * story: 422 like an unknown kind), `subject` - the achievement key for `achievement`, forbidden for `streak`. No text, no URL: everything
 * else is the server's.
 */
final class StoryShareRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $input = $this->input('kind');
        $kind = is_string($input) ? ShareKind::tryFrom($input) : null;
        $withSubject = $kind !== null && $kind->forStory() && $kind->needsSubject();

        return [
            'kind' => ['required', 'string', Rule::in(array_map(static fn (ShareKind $k): string => $k->value, ShareKind::storyKinds()))],
            'subject' => $withSubject
                ? ['required', 'string', 'regex:'.ShareMessageBuilder::SUBJECT_PATTERN]
                : ['prohibited'],
        ];
    }

    public function kind(): ShareKind
    {
        return ShareKind::from($this->string('kind')->toString());
    }

    public function subject(): ?string
    {
        $subject = $this->input('subject');

        return is_string($subject) ? $subject : null;
    }
}
