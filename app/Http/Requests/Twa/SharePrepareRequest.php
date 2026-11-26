<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Engagement\Enums\ShareKind;
use App\Domain\Engagement\Services\ShareMessageBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/twa/share/prepare (docs/04 A.22): `kind` from the closed list of the kinds available now, `subject` - the achievement key or
 * the stage id for the kinds that have one, nothing for the invitations. No text, no URL, no user id: everything else is the server's.
 */
final class SharePrepareRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $input = $this->input('kind');
        $kind = is_string($input) ? ShareKind::tryFrom($input) : null;
        $withSubject = $kind !== null && $kind->needsSubject();

        return [
            'kind' => ['required', 'string', Rule::in(array_map(static fn (ShareKind $k): string => $k->value, ShareKind::available()))],
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
