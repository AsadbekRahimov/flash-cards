<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Engagement\Enums\ShareKind;
use App\Models\Learner;
use InvalidArgumentException;

/**
 * What the Mini App reports about "share" (docs/36 H7, `POST /api/twa/share/events`): the prepared message was sent (`share.sent`), or
 * the old path `t.me/share/url` was opened instead (`share.fallback {reason}`). Analytics only - the client cannot be checked, so
 * nothing else depends on these rows (the "shared" marks of achievements and cities stay on their own routes).
 */
final class ShareAnalytics
{
    public const EVENT_SENT = 'sent';

    public const EVENT_FALLBACK = 'fallback';

    /** `old_client` - no `shareMessage` (Mini Apps < 8.0); `error` - `/share/prepare` refused or `shareMessage` failed. */
    public const FALLBACK_REASONS = ['old_client', 'error'];

    public function __construct(private readonly ProductEventRecorder $events) {}

    /**
     * @param  string  $event  {@see self::EVENT_SENT} or {@see self::EVENT_FALLBACK}
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
            default => throw new InvalidArgumentException("Unknown share event '{$event}'."),
        };
    }
}
