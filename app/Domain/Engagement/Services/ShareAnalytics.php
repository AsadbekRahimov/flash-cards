<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Engagement\Enums\ShareKind;
use App\Models\Learner;
use InvalidArgumentException;

/**
 * What the Mini App reports about "share" (docs/36 H7, docs/40 §2.4; `POST /api/twa/share/events`): the prepared message was sent
 * (`share.sent`), or the old path `t.me/share/url` was opened instead (`share.fallback {reason}`); a story was opened in Telegram
 * (`share.sent {via: story}` - `shareToStory` has no callback, so "opened" is all the client knows) or the story gave way to the
 * message through the bot (`share.fallback {reason, via: story}`). Analytics only - the client cannot be checked, so nothing else
 * depends on these rows (the "shared" marks of achievements and cities stay on their own routes).
 */
final class ShareAnalytics
{
    public const EVENT_SENT = 'sent';

    public const EVENT_FALLBACK = 'fallback';

    public const EVENT_STORY_SENT = 'story_sent';

    public const EVENT_STORY_FALLBACK = 'story_fallback';

    public const EVENTS = [self::EVENT_SENT, self::EVENT_FALLBACK, self::EVENT_STORY_SENT, self::EVENT_STORY_FALLBACK];

    /** `old_client` - no `shareMessage` (Mini Apps < 8.0); `error` - `/share/prepare` refused or `shareMessage` failed. */
    public const FALLBACK_REASONS = ['old_client', 'error'];

    /**
     * `old_client` - no `shareToStory` (Mini Apps < 7.8); `unavailable` - `/share/story` answered 403 / 404 / 429 / 503 (no https, a
     * series of 0, the limit); `error` - the SDK refused the call (`WebAppMediaUrlInvalid` and the like).
     */
    public const STORY_FALLBACK_REASONS = ['old_client', 'unavailable', 'error'];

    public function __construct(private readonly ProductEventRecorder $events) {}

    /**
     * @param  string  $event  one of {@see EVENTS}
     *
     * @throws InvalidArgumentException an unknown event or fallback reason (the request validates both first)
     */
    public function record(Learner $learner, string $event, ShareKind $kind, ?string $reason = null): void
    {
        match ($event) {
            self::EVENT_SENT => $this->events->record(ProductEventName::ShareSent, ['kind' => $kind->value], learner: $learner),
            self::EVENT_FALLBACK => $this->events->record(ProductEventName::ShareFallback, [
                'kind' => $kind->value,
                'reason' => in_array($reason, self::FALLBACK_REASONS, true) ? $reason : throw new InvalidArgumentException('Unknown fallback reason.'),
            ], learner: $learner),
            self::EVENT_STORY_SENT => $this->events->record(ProductEventName::ShareSent, ['kind' => $kind->value, 'via' => 'story'], learner: $learner),
            self::EVENT_STORY_FALLBACK => $this->events->record(ProductEventName::ShareFallback, [
                'kind' => $kind->value,
                'reason' => in_array($reason, self::STORY_FALLBACK_REASONS, true) ? $reason : throw new InvalidArgumentException('Unknown story fallback reason.'),
                'via' => 'story',
            ], learner: $learner),
            default => throw new InvalidArgumentException("Unknown share event '{$event}'."),
        };
    }
}
