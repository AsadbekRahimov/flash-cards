<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Engagement\Enums\ShareKind;
use App\Domain\Engagement\Services\ShareAnalytics;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/twa/share/events (docs/04 A.22, A.26): `{event: sent | fallback | story_sent | story_fallback, kind, reason?}`; `reason`
 * only, and always, for the two fallbacks (each with its own list). `kind` - a kind of the message for the first two, of the story
 * for the other two.
 */
final class ShareEventRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $event = $this->input('event');
        $kinds = match ($event) {
            ShareAnalytics::EVENT_STORY_SENT, ShareAnalytics::EVENT_STORY_FALLBACK => ShareKind::storyKinds(),
            default => ShareKind::available(),
        };
        $reasons = match ($event) {
            ShareAnalytics::EVENT_FALLBACK => ShareAnalytics::FALLBACK_REASONS,
            ShareAnalytics::EVENT_STORY_FALLBACK => ShareAnalytics::STORY_FALLBACK_REASONS,
            default => null,
        };

        return [
            'event' => ['required', 'string', Rule::in(ShareAnalytics::EVENTS)],
            'kind' => ['required', 'string', Rule::in(array_map(static fn (ShareKind $k): string => $k->value, $kinds))],
            'reason' => $reasons === null
                ? ['prohibited']
                : ['required', 'string', Rule::in($reasons)],
        ];
    }

    /** @return 'sent'|'fallback'|'story_sent'|'story_fallback' */
    public function event(): string
    {
        $event = $this->input('event');

        return match ($event) {
            ShareAnalytics::EVENT_FALLBACK, ShareAnalytics::EVENT_STORY_SENT, ShareAnalytics::EVENT_STORY_FALLBACK => $event,
            default => ShareAnalytics::EVENT_SENT,
        };
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
