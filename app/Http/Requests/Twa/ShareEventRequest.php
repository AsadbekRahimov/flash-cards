<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Engagement\Enums\ShareKind;
use App\Domain\Engagement\Services\ShareAnalytics;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/twa/share/events (docs/04 A.22): `{event: sent | fallback, kind, reason?}`; `reason` only, and always, for `fallback`. */
final class ShareEventRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $fallback = $this->input('event') === ShareAnalytics::EVENT_FALLBACK;

        return [
            'event' => ['required', 'string', Rule::in([ShareAnalytics::EVENT_SENT, ShareAnalytics::EVENT_FALLBACK])],
            'kind' => ['required', 'string', Rule::in(array_map(static fn (ShareKind $k): string => $k->value, ShareKind::available()))],
            'reason' => $fallback
                ? ['required', 'string', Rule::in(ShareAnalytics::FALLBACK_REASONS)]
                : ['prohibited'],
        ];
    }

    /** @return 'sent'|'fallback' */
    public function event(): string
    {
        return $this->input('event') === ShareAnalytics::EVENT_FALLBACK ? ShareAnalytics::EVENT_FALLBACK : ShareAnalytics::EVENT_SENT;
    }

    public function kind(): ShareKind
    {
        return ShareKind::from($this->string('kind')->toString());
    }

    public function reason(): ?string
    {
        $reason = $this->input('reason');

        return is_string($reason) ? $reason : null;
    }
}
